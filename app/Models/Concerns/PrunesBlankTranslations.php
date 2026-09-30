<?php

namespace App\Models\Concerns;

/**
 * Use together with Spatie's HasTranslations. Drops blank (null / '') locales
 * from translatable JSON on save, so "missing" always means "key absent" and
 * scopes like Question::translatedIn() can rely on it.
 */
trait PrunesBlankTranslations
{
    protected static function bootPrunesBlankTranslations(): void
    {
        static::saving(function ($model) {
            foreach ($model->getTranslatableAttributes() as $attribute) {
                if ($model->isDirty($attribute)) {
                    // getTranslations() already filters out blank values
                    $model->attributes[$attribute] = json_encode(
                        $model->getTranslations($attribute),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    );
                }
            }
        });
    }
}
