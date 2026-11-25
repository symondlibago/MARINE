<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day of the crew ration sheet: who ate, at which sitting, in which port.
 *
 * Only counts live here. Meals, rations and the benchmark value are derived —
 * see App\Support\Inventory\RationSheet.
 */
class CrewRation extends Model
{
    protected $table = 'provision_crew_rations';

    /**
     * Who was fed, and at which sitting.
     *
     * The split is a billing one, not a seating one. Crew and passengers are
     * charged by the ration — a man fed for a day — while visitors are charged
     * by the meal at a lower rate, and the charterer's people often on a rate
     * of their own. "visitors" unqualified means the owner's and crew's own
     * guests, which is the common case.
     */
    public const CATEGORIES = [
        'crew' => ['breakfast_crew', 'lunch_crew', 'dinner_crew'],
        'passenger' => ['breakfast_passenger', 'lunch_passenger', 'dinner_passenger'],
        'visitor' => ['breakfast_visitors', 'lunch_visitors', 'dinner_visitors'],
        'charterer' => ['breakfast_charterer', 'lunch_charterer', 'dinner_charterer'],
    ];

    /** Billed per ration (meals / 3) rather than per meal. */
    public const BY_RATION = ['crew', 'passenger'];

    /** Every count column, flattened. */
    public const COUNTS = [
        'breakfast_crew', 'lunch_crew', 'dinner_crew',
        'breakfast_passenger', 'lunch_passenger', 'dinner_passenger',
        'breakfast_visitors', 'lunch_visitors', 'dinner_visitors',
        'breakfast_charterer', 'lunch_charterer', 'dinner_charterer',
    ];

    protected $fillable = [
        'vessel_id',
        'date',
        'port',
        'location',
        'breakfast_crew', 'lunch_crew', 'dinner_crew',
        'breakfast_passenger', 'lunch_passenger', 'dinner_passenger',
        'breakfast_visitors', 'lunch_visitors', 'dinner_visitors',
        'breakfast_charterer', 'lunch_charterer', 'dinner_charterer',
        'additional_persons',
        'purchases_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'breakfast_crew' => 'integer', 'lunch_crew' => 'integer', 'dinner_crew' => 'integer',
        'breakfast_passenger' => 'integer', 'lunch_passenger' => 'integer', 'dinner_passenger' => 'integer',
        'breakfast_visitors' => 'integer', 'lunch_visitors' => 'integer', 'dinner_visitors' => 'integer',
        'breakfast_charterer' => 'integer', 'lunch_charterer' => 'integer', 'dinner_charterer' => 'integer',
        'purchases_amount' => 'decimal:2',
    ];

    /**
     * A date, stored as a date.
     *
     * Not the plain 'date' cast: that writes through the connection's datetime
     * format, so the column ends up holding "2026-06-01 00:00:00". MySQL
     * truncates that back to a date on the way in and hides the problem;
     * SQLite keeps it verbatim, and then the day this row is looked up by
     * ("where date = 2026-06-01") never matches it, and the last day of a
     * month falls outside a between on the month's bounds.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class, 'vessel_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A day nobody ate on carries no information and is not worth a row. */
    public function isEmpty(): bool
    {
        foreach (self::COUNTS as $field) {
            if ((int) $this->{$field} > 0) {
                return false;
            }
        }

        if ((float) $this->purchases_amount > 0) {
            return false;
        }

        foreach (['port', 'location', 'additional_persons', 'notes'] as $text) {
            if (($this->{$text} ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
}
