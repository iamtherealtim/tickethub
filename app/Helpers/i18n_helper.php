<?php

/**
 * Tiny i18n conveniences. Load with helper('i18n') at the top of a view.
 */

if (! function_exists('th_t')) {
    /**
     * Translate a stored vocabulary value (status, priority, role, ...) by key,
     * falling back to the raw value when no translation exists. lang() returns
     * the key itself when a line is missing, so that is the signal we test for.
     *
     *   th_t('Common.status.' . $t['status'], $t['status'])
     */
    function th_t(string $key, string $fallback, array $args = []): string
    {
        $out = lang($key, $args);

        return ($out === $key || $out === '') ? $fallback : $out;
    }
}

if (! function_exists('th_lang_url')) {
    /**
     * The current URL with ?lang=<locale> added (or replaced), for the EN/FR switcher.
     * The Locale filter stores the choice in the session and strips the parameter.
     */
    function th_lang_url(string $locale): string
    {
        $uri = clone current_url(true);
        $uri->addQuery('lang', $locale);

        return (string) $uri;
    }
}

if (! function_exists('th_locale')) {
    /** The locale the current request renders in ("en", "fr"). */
    function th_locale(): string
    {
        return service('request')->getLocale();
    }
}

if (! function_exists('th_lang_switcher')) {
    /**
     * Inline EN / FR switcher. $cls styles the wrapper; links carry aria-current.
     */
    function th_lang_switcher(string $cls = ''): string
    {
        $cur  = th_locale();
        $html = '<span class="inline-flex items-center gap-0.5 ' . $cls . '" aria-label="' . esc(lang('Common.label.language'), 'attr') . '">';
        foreach (config('App')->supportedLocales as $loc) {
            $on = $loc === $cur;
            $html .= '<a href="' . esc(th_lang_url($loc), 'attr') . '" hreflang="' . esc($loc, 'attr') . '"'
                . ($on ? ' aria-current="true"' : '')
                . ' title="' . esc(lang('Common.lang.' . $loc), 'attr') . '"'
                . ' class="h-7 px-2 rounded-md text-[11.5px] font-semibold uppercase tracking-wide inline-flex items-center transition '
                . ($on ? 'bg-canvas text-ink border border-line' : 'text-faint hover:text-ink') . '">' . esc(strtoupper($loc)) . '</a>';
        }

        return $html . '</span>';
    }
}

/* ---------- theme controls (labels are translated, hence they live here) ---------- */

if (! function_exists('th_theme_toggle')) {
    /**
     * Header button that cycles system → light → dark. public/assets/js/theme.js keeps
     * data-theme-state and the aria-label current; CSS in partials/head.php shows one icon.
     */
    function th_theme_toggle(string $cls = ''): string
    {
        $sun  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-[18px] h-[18px]"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41"/></svg>';
        $moon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-[18px] h-[18px]"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
        $mon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-[18px] h-[18px]"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/></svg>';
        $label = lang('Common.theme.toggle', [lang('Common.theme.system')]);

        return '<button type="button" data-theme-toggle data-theme-state="system" aria-label="' . esc($label, 'attr') . '" title="' . esc($label, 'attr') . '"'
            . ' class="w-9 h-9 grid place-items-center rounded-lg text-muted hover:bg-canvas ' . $cls . '">'
            . '<span data-theme-icon="system">' . $mon . '</span><span data-theme-icon="light">' . $sun . '</span><span data-theme-icon="dark">' . $moon . '</span></button>';
    }
}

if (! function_exists('th_theme_picker')) {
    /** Segmented System / Light / Dark control for the account menu. */
    function th_theme_picker(): string
    {
        $html = '<div class="inline-flex items-center gap-1" role="group" aria-label="' . esc(lang('Common.label.theme'), 'attr') . '">';
        foreach (['system', 'light', 'dark'] as $s) {
            $html .= '<button type="button" data-theme-set="' . $s . '" aria-pressed="false" class="h-8 px-3 rounded-lg border border-line text-[12.5px] font-medium text-ink-500 hover:bg-canvas transition">'
                . esc(lang('Common.theme.' . $s)) . '</button>';
        }

        return $html . '</div>';
    }
}
