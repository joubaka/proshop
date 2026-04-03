<?php

namespace App;

/**
 * Applies patches to vendor files for PHP 8.x compatibility.
 * Called automatically by Composer post-install/update scripts.
 */
class ComposerPatches
{
    public static function apply()
    {
        static::patchCarbonCreator();
        static::patchFormBuilder();
    }

    /**
     * Fix Carbon's setLastErrors() receiving false from DateTime::getLastErrors() on PHP 8.x
     */
    private static function patchCarbonCreator()
    {
        $file = __DIR__ . '/../vendor/nesbot/carbon/src/Carbon/Traits/Creator.php';

        if (!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);

        // Patch: parent::getLastErrors() returns false on PHP 8.x instead of array
        $search = 'static::setLastErrors(parent::getLastErrors());';
        $replace = 'static::setLastErrors(parent::getLastErrors() ?: []);';

        if (strpos($content, $search) !== false) {
            $content = str_replace($search, $replace, $content);

            // Also patch the variable assignment form
            $search2 = '$lastErrors = parent::getLastErrors();';
            $replace2 = '$lastErrors = parent::getLastErrors() ?: [];';
            $content = str_replace($search2, $replace2, $content);

            file_put_contents($file, $content);
            echo "  > Applied Carbon PHP 8.x compatibility patch\n";
        }
    }

    /**
     * Fix method_exists() receiving null on PHP 8.x in LaravelCollective FormBuilder
     */
    private static function patchFormBuilder()
    {
        $file = __DIR__ . '/../vendor/laravelcollective/html/src/FormBuilder.php';

        if (!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);

        $search = 'if (method_exists($this->model, \'getFormValue\'))';
        $replace = 'if ($this->model !== null && method_exists($this->model, \'getFormValue\'))';

        if (strpos($content, $search) !== false && strpos($content, $replace) === false) {
            $content = str_replace($search, $replace, $content);
            file_put_contents($file, $content);
            echo "  > Applied FormBuilder PHP 8.x compatibility patch\n";
        }
    }
}
