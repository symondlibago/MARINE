<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\CrewMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Who is aboard.
 *
 * The ration sheet counts meals and bills the owner from the totals. What it
 * could not say was whose. Twenty meals a day is a figure; twenty named men is
 * a record, and the master is the one who gets asked to stand behind it.
 *
 * The roster also saves typing: the day's crew count comes from the number
 * aboard instead of being entered again on every line of the sheet.
 */
class CrewController extends InventoryController
{
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        $crew = CrewMember::where('vessel_id', $vessel->id)
            ->when(! $request->boolean('all'), fn ($q) => $q->aboard())
            ->orderBy('category')->orderBy('sort')->orderBy('name')
            ->get();

        return $this->ok([
            'crew' => $crew->map(fn (CrewMember $m) => $this->present($m))->values(),
            'categories' => collect(CrewMember::CATEGORIES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'aboard' => [
                'total' => $crew->where('is_active', true)->count(),
                'crew' => $crew->where('is_active', true)->where('category', CrewMember::CREW)->count(),
                'passenger' => $crew->where('is_active', true)->where('category', CrewMember::PASSENGER)->count(),
                'charterer' => $crew->where('is_active', true)->where('category', CrewMember::CHARTERER)->count(),
            ],
            // What the vessel's settings say she carries, so a roster that has
            // drifted from it is visible rather than quietly wrong.
            'crew_size' => (int) $vessel->crew_size,
        ]);
    }

    public function store(Request $request)
    {
        $vessel = $this->vessel($request);
        $data = $this->validated($request);

        $member = CrewMember::create($data + [
            'vessel_id' => $vessel->id,
            'is_active' => true,
            'sort' => (int) CrewMember::where('vessel_id', $vessel->id)->max('sort') + 1,
        ]);

        return $this->ok(
            ['member' => $this->present($member)],
            "{$member->name} signed on.",
            201
        );
    }

    /**
     * Add several at once.
     *
     * A crew change is a dozen people on one day. One name at a time is how a
     * roster ends up half-entered and then ignored, so names can be pasted in
     * a line at a time — "Chief Cook, Juan Dela Cruz" or just the name.
     */
    public function bulk(Request $request)
    {
        $vessel = $this->vessel($request);

        $data = $request->validate([
            'names' => ['required', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'in:'.implode(',', array_keys(CrewMember::CATEGORIES))],
        ]);

        $existing = CrewMember::where('vessel_id', $vessel->id)
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim($n)))
            ->flip();

        $sort = (int) CrewMember::where('vessel_id', $vessel->id)->max('sort');
        $added = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $vessel, $existing, &$sort, &$added, &$skipped) {
            foreach (preg_split('/\r\n|\r|\n/', $data['names']) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                // "Chief Cook, Juan Dela Cruz" — rank first, then the name.
                // A bare name is just as acceptable.
                $rank = null;
                $name = $line;

                if (str_contains($line, ',')) {
                    [$first, $rest] = array_map('trim', explode(',', $line, 2));
                    if ($first !== '' && $rest !== '') {
                        $rank = $first;
                        $name = $rest;
                    }
                }

                if ($existing->has(mb_strtolower($name))) {
                    $skipped++;

                    continue;
                }

                CrewMember::create([
                    'vessel_id' => $vessel->id,
                    'name' => mb_substr($name, 0, 255),
                    'rank' => $rank ? mb_substr($rank, 0, 255) : null,
                    'category' => $data['category'] ?? CrewMember::CREW,
                    'is_active' => true,
                    'sort' => ++$sort,
                ]);

                $existing->put(mb_strtolower($name), true);
                $added++;
            }
        });

        return $this->ok(
            [],
            $added === 0
                ? 'Everyone on that list is already aboard.'
                : "{$added} signed on".($skipped ? ", {$skipped} already aboard." : '.')
        );
    }

    public function update(Request $request, CrewMember $crew)
    {
        if ((int) $crew->vessel_id !== $this->vessel($request)->id) {
            abort(404, 'That person is not on this vessel.');
        }

        $data = $this->validated($request, true);

        if (array_key_exists('is_active', $data)) {
            $crew->is_active = $data['is_active'];

            // Signed off today unless a date was given, so the roster says
            // when rather than just that it happened.
            if (! $data['is_active'] && ! $crew->left_on && ! array_key_exists('left_on', $data)) {
                $crew->left_on = now()->toDateString();
            }

            unset($data['is_active']);
        }

        $crew->fill($data)->save();

        return $this->ok(['member' => $this->present($crew->fresh())], 'Updated.');
    }

    /**
     * Take somebody off the roster entirely.
     *
     * Signing off is nearly always what is wanted — last month's ration report
     * still has to name the men it was billed for. This is for a name typed in
     * by mistake.
     */
    public function destroy(Request $request, CrewMember $crew)
    {
        if ((int) $crew->vessel_id !== $this->vessel($request)->id) {
            abort(404, 'That person is not on this vessel.');
        }

        $name = $crew->name;
        $crew->delete();

        return $this->ok([], "{$name} removed from the roster.");
    }

    private function present(CrewMember $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'rank' => $member->rank,
            'label' => $member->label(),
            'category' => $member->category,
            'category_label' => $member->categoryLabel(),
            'joined_on' => $member->joined_on?->toDateString(),
            'left_on' => $member->left_on?->toDateString(),
            'is_active' => (bool) $member->is_active,
            'note' => $member->note,
        ];
    }

    private function validated(Request $request, bool $editing = false): array
    {
        return $request->validate([
            'name' => [$editing ? 'sometimes' : 'required', 'string', 'max:255'],
            'rank' => ['nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', array_keys(CrewMember::CATEGORIES))],
            'joined_on' => ['nullable', 'date'],
            'left_on' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
