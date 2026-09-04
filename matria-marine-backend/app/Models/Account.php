<?php

namespace App\Models;

use App\Support\GstCodes;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the chart of accounts.
 *
 * The chart is the spine of the accounting module: every invoice, purchase and
 * expense points at one of these codes, and the GST treatment, the trial-balance
 * side and the statement an amount appears on are all read from here. Nothing
 * downstream stores its own copy of any of that.
 */
class Account extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'gst_code',
        'description',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /* ---------------------------------------------------------------- types */

    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    public const EQUITY = 'equity';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const TYPES = [
        self::ASSET => 'Asset',
        self::LIABILITY => 'Liability',
        self::EQUITY => 'Equity',
        self::INCOME => 'Income',
        self::EXPENSE => 'Expense',
    ];

    /**
     * The accounts a document of each kind defaults to.
     *
     * Marine supplies are almost always zero-rated exports, so a sale defaults
     * to 4100 and Dennis switches it to 4000 on the rare standard-rated job.
     * What we buy for a job is cost of sales; our own overheads are 5100.
     */
    public const DEFAULT_SALES = '4100';

    /** Where a sale goes when GST was actually charged on it. */
    public const DEFAULT_SALES_STANDARD = '4000';

    public const DEFAULT_PURCHASE = '5000';

    public const DEFAULT_EXPENSE = '5100';

    /* ----------------------------------------------------------- behaviour */

    /**
     * Which side of the trial balance this account's balance normally sits on.
     *
     * Assets and expenses are debit-normal; liabilities, equity and income are
     * credit-normal. The trial balance uses this to decide which column a
     * balance belongs in, and it must never be stored per-document.
     */
    public function normalBalance(): string
    {
        return in_array($this->type, [self::ASSET, self::EXPENSE], true) ? 'debit' : 'credit';
    }

    /** Balance sheet, or income statement? */
    public function statement(): string
    {
        return in_array($this->type, [self::INCOME, self::EXPENSE], true)
            ? 'income_statement'
            : 'balance_sheet';
    }

    public function gstLabel(): string
    {
        return GstCodes::label($this->gst_code);
    }

    /* --------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort')->orderBy('code');
    }

    /* --------------------------------------------------------------- lookup */

    /**
     * The whole chart keyed by code, cached for the life of the request.
     *
     * Reports resolve a code per document row; without this a 500-invoice GST
     * return would be 500 lookups. Nothing here changes mid-request, so a
     * static is honest — and it resets between requests, so an edit to the
     * chart takes effect on the next page load.
     */
    public static function chart(): \Illuminate\Support\Collection
    {
        static $chart = null;

        if ($chart !== null) {
            return $chart;
        }

        // Migrations are run by hand on Railway, so there is a window where this
        // code is live and the table is not. An empty chart in that window is
        // survivable — every caller treats "not on the chart" as unclassified —
        // whereas throwing would take down invoice and PO screens.
        if (! \Illuminate\Support\Facades\Schema::hasTable('accounts')) {
            return $chart = collect();
        }

        return $chart = static::ordered()->get()->keyBy('code');
    }

    /** @return list<string> every code on the chart */
    public static function codes(): array
    {
        return static::chart()->keys()->all();
    }

    /**
     * Validation for an account code posted from a form.
     *
     * Deliberately permissive when the chart is empty — that only happens
     * before the seeder has run, and refusing every save during a deploy window
     * would be a worse failure than briefly accepting an unchecked code. Once
     * the chart exists, only codes on it are allowed, which is what stops the
     * free-text typo (`31530`) that the old `accounting_code` field collected.
     */
    public static function validationRule(): array
    {
        return ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
            if ($value === null || $value === '' || static::chart()->isEmpty()) {
                return;
            }

            if (! static::chart()->has(trim($value))) {
                $fail("“{$value}” is not on the chart of accounts.");
            }
        }];
    }

    /** Resolve a code to its account, or null if it is not on our chart. */
    public static function find_by_code(?string $code): ?self
    {
        return $code ? static::chart()->get(trim($code)) : null;
    }

    /**
     * The GST code for an account code, or null when we do not recognise it.
     *
     * This is the single derivation the whole GST return rests on. An
     * unrecognised code returns null rather than guessing — an amount with no
     * treatment is reported as unclassified, never silently dropped into a box.
     */
    public static function gstCodeFor(?string $code): ?string
    {
        return static::find_by_code($code)?->gst_code;
    }
}
