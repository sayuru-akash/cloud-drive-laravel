import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { ResolvedTheme, ThemePreference } from '@/types';

export type { ResolvedTheme, ThemePreference };

export type UseThemeReturn = {
    theme: Ref<ThemePreference>;
    resolvedTheme: ComputedRef<ResolvedTheme>;
    updateThemePreference: (value: ThemePreference) => void;
};

export function applyTheme(value: ThemePreference): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredTheme = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('theme') as ThemePreference | null;
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    const currentTheme = getStoredTheme();

    applyTheme(currentTheme || 'system');
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const savedTheme = getStoredTheme();
    applyTheme(savedTheme || 'system');

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const theme = ref<ThemePreference>('system');

export function useTheme(): UseThemeReturn {
    onMounted(() => {
        const savedTheme = localStorage.getItem(
            'theme',
        ) as ThemePreference | null;

        if (savedTheme) {
            theme.value = savedTheme;
        }
    });

    const resolvedTheme = computed<ResolvedTheme>(() => {
        if (theme.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return theme.value;
    });

    function updateThemePreference(value: ThemePreference) {
        theme.value = value;

        localStorage.setItem('theme', value);
        setCookie('theme', value);

        applyTheme(value);
    }

    return {
        theme,
        resolvedTheme,
        updateThemePreference,
    };
}
