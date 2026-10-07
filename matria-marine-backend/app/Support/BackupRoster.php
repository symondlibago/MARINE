<?php

namespace App\Support;

use App\Models\Payroll\Employee;
use Illuminate\Support\Collection;

/**
 * The payroll employee master, as one thing that can be backed up.
 *
 * Every other type in {@see BackupBundle} is a document with a row and a
 * number of its own. The staff list is not — it is a table, and what Dru asked
 * to keep is the list as it stands. So it is wrapped here to give the bundle
 * the handful of things it expects of a model: an id (always 1, there being
 * only ever one list), and a date.
 *
 * Deliberately a value object rather than an Eloquent model. There is no
 * matching table, and inventing one would mean a migration for something that
 * exists only to be read.
 */
class BackupRoster
{
    /** There is only ever one roster, so its "id" never varies. */
    public const ID = 1;

    public int $id = self::ID;

    /** @param  Collection<int, Employee>  $employees */
    private function __construct(
        public readonly Collection $employees,
        public readonly ?\Illuminate\Support\Carbon $updated_at,
    ) {}

    public static function load(): self
    {
        $employees = Employee::query()
            ->orderBy('status')
            ->orderBy('code')
            ->get();

        return new self($employees, $employees->max('updated_at'));
    }

    /**
     * Read like a model: anything a document would have and a list does not
     * comes back null.
     *
     * {@see BackupBundle} asks every type for the same handful of things — a
     * status, a currency, a grand total — and Eloquent answers null for the
     * ones a given table has no column for. This keeps the list answering the
     * same way, instead of raising "undefined property" from inside a backup.
     */
    public function __get(string $name): mixed
    {
        return null;
    }

    /** Stands in for a document's status line. */
    public function statusLine(): string
    {
        $active = $this->employees->where('status', 'active')->count();

        return sprintf('%d active of %d on the books', $active, $this->employees->count());
    }

    /**
     * The list, with the two identifiers that have no business leaving the
     * system taken out.
     *
     * A backup folder is copied onto sticks and sync drives. An NRIC and a
     * bank reference are what someone would need to impersonate a person or
     * redirect their pay, and neither is needed to know who worked here and
     * what they were paid — which is what this list is being kept for.
     */
    public function toArray(): array
    {
        return $this->employees
            ->map(fn (Employee $e) => collect($e->toArray())->except(['nric', 'bank_ref'])->all())
            ->values()
            ->all();
    }
}
