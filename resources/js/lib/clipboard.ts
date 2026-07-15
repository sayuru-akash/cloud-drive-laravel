export async function copyTextToClipboard(text: string): Promise<boolean> {
    if (!text) {
        return false;
    }

    try {
        if (window.location.protocol === 'https:' && navigator.clipboard) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch {
        // Fall through to the selection fallback used by restricted browsers.
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(textarea);
    }
}
