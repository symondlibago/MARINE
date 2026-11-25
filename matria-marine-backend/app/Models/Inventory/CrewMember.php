<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * Somebody aboard who eats.
 *
 * The roster behind the ration sheet. It exists so a ration report can name
 * the men it bills for — twenty meals a day is a figure, twenty named men is a
 * record — and so the day's crew count does not have to be typed twice.
 *
 * Which category a person falls under matters to the invoice, not to the
 * galley: crew and passengers are billed by the ration, the charterer's people
 * by the meal and often at a rate of their own.
 */
class CrewMember extends Model
{
    protected $table = 'provision_crew';

    public const CREW = 'crew';
    public const PASSENGER = 'passenger';
    public const CHARTERER = 'charterer';

    public const CATEGORIES = [
        self::CREW => 'Crew',
        self::PASSENGER => 'Passenger',
        self::CHARTERER => "Charterer's",
    ];

    protected $fillable = [
        'vessel_id',
        'name',
        'rank',
        'category',
        'joined_on',
        'left_on',
        'is_active',
        'note',
        'sort',
    ];

    protected $casts = [
        'joined_on' => 'date',
        'left_on' => 'date',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Set here as well as on the column.
     *
     * The database default fills the row, but the model instance that did the
     * inserting never learns about it — so the object handed straight back to
     * the API had no category at all, and anything reading one got null.
     */
    protected $attributes = [
        'category' => self::CREW,
        'is_active' => true,
    ];

    public function vessel()
    {
        return $this->belongsTo(Vessel::class, 'vessel_id');
    }

    public function scopeAboard($query)
    {
        return $query->where('is_active', true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) ($this->category ?: self::CATEGORIES[self::CREW]);
    }

    /** "Chief Cook — Juan Dela Cruz", the way a report reads. */
    public function label(): string
    {
        return $this->rank ? "{$this->rank} — {$this->name}" : $this->name;
    }
}
