<?php

namespace App\Models\Concerns;

use App\Support\DocumentOrigin;
use Illuminate\Database\Eloquent\Model;

/**
 * Stamp a document with the operation that raised it, and inherit it.
 *
 * The inheritance is the point. An invoice is raised off an offer, which was
 * raised off an enquiry, which may have begun as a vessel's requisition. If
 * each controller had to remember to pass the origin down, one of them would
 * not, and a provisions invoice would turn up in the chandling accounts — the
 * one failure this whole column exists to prevent.
 *
 * So a model declares which of its columns point at a parent document, in the
 * order they should be trusted, and the origin comes down the chain by itself:
 *
 *     protected array $originParents = ['offer_id' => Offer::class];
 *
 * A document with no parent, and nothing said about it, is procurement — which
 * is what every document in the system was before this existed.
 */
trait StampsOrigin
{
    public static function bootStampsOrigin(): void
    {
        static::creating(function (Model $model) {
            if (! empty($model->origin)) {
                return;
            }

            $model->origin = $model->inheritedOrigin() ?? DocumentOrigin::PROCUREMENT;
        });
    }

    /** Narrow to one operation's documents. Null means all of them. */
    public function scopeOrigin($query, ?string $origin)
    {
        return DocumentOrigin::apply($query, $origin, $this->getTable().'.origin');
    }

    public function isFromInventory(): bool
    {
        return $this->origin === DocumentOrigin::INVENTORY;
    }

    public function originLabel(): string
    {
        return DocumentOrigin::label($this->origin);
    }

    /**
     * The origin of the first parent this document actually has.
     *
     * Only the column is read, not the relation, so this costs one small query
     * per document created and never loads a parent's whole row.
     */
    protected function inheritedOrigin(): ?string
    {
        foreach ($this->originParents ?? [] as $column => $parent) {
            $id = $this->getAttribute($column);

            if (! $id) {
                continue;
            }

            $origin = $parent::whereKey($id)->value('origin');

            if ($origin) {
                return $origin;
            }
        }

        return null;
    }
}
