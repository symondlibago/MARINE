<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Support\GstCodes;
use Illuminate\Support\Facades\Schema;

/**
 * A financial document that is classified against the chart of accounts.
 *
 * The document stores an account code and nothing else about its accounting
 * treatment. Its GST code, the F5 box it lands in, the side of the trial
 * balance it sits on and the statement it appears on are all read back through
 * {@see Account}. One field, so they cannot drift apart.
 *
 * Using it: add the trait and declare the default.
 *
 *     class CustomerInvoice extends Model
 *     {
 *         use HasAccountCode;
 *
 *         protected string $defaultAccountCode = Account::DEFAULT_SALES;
 *     }
 */
trait HasAccountCode
{
    public static function bootHasAccountCode(): void
    {
        static::creating(function ($model) {
            if (! $model->account_code && static::accountCodeReady()) {
                $model->account_code = $model->defaultAccountCode();
            }
        });
    }

    /**
     * Where a document of this kind is booked unless somebody says otherwise.
     *
     * Overridable per model via the `$defaultAccountCode` property; falls back
     * to cost of sales so a model that forgets to declare one is still coded to
     * something real rather than to nothing.
     */
    public function defaultAccountCode(): string
    {
        return $this->defaultAccountCode ?? Account::DEFAULT_PURCHASE;
    }

    /**
     * Is the column actually deployed?
     *
     * Migrations are run by hand on Railway, so there is always a window where
     * this code is live and the column is not. Classification stays switched
     * off in that window rather than throwing — creating an invoice must not
     * start failing because a migration has not been run yet.
     */
    protected static function accountCodeReady(): bool
    {
        static $cache = [];
        $table = (new static)->getTable();

        return $cache[$table] ??= Schema::hasColumn($table, 'account_code');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_code', 'code');
    }

    /** The account this document is booked to, or null if it is unclassified. */
    public function accountRecord(): ?Account
    {
        return Account::find_by_code($this->account_code);
    }

    /**
     * The GST treatment, derived — never stored.
     *
     * Null means the document carries a code that is not on our chart (or no
     * code at all). Reports surface that as unclassified rather than guessing a
     * box for it.
     */
    public function gstCode(): ?string
    {
        return Account::gstCodeFor($this->account_code);
    }

    public function gstLabel(): string
    {
        return GstCodes::label($this->gstCode());
    }
}
