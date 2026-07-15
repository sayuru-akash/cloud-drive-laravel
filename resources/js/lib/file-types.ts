export type FileTypeKind =
    | 'archive'
    | 'audio'
    | 'code'
    | 'document'
    | 'image'
    | 'pdf'
    | 'presentation'
    | 'spreadsheet'
    | 'text'
    | 'unknown'
    | 'video';

const extensionLabels: Record<string, string> = {
    csv: 'CSV spreadsheet',
    doc: 'Word document',
    docx: 'Word document',
    gz: 'Gzip archive',
    jpeg: 'JPEG image',
    jpg: 'JPEG image',
    json: 'JSON file',
    key: 'Keynote presentation',
    md: 'Markdown file',
    numbers: 'Numbers spreadsheet',
    pages: 'Pages document',
    pdf: 'PDF document',
    png: 'PNG image',
    ppt: 'PowerPoint presentation',
    pptx: 'PowerPoint presentation',
    rar: 'RAR archive',
    svg: 'SVG image',
    tar: 'TAR archive',
    txt: 'Text file',
    xls: 'Excel spreadsheet',
    xlsx: 'Excel spreadsheet',
    zip: 'ZIP archive',
};

const codeExtensions = new Set([
    'css',
    'html',
    'js',
    'jsx',
    'php',
    'py',
    'rb',
    'ts',
    'tsx',
    'vue',
    'xml',
    'yaml',
    'yml',
]);

const extensionKinds: Record<string, FileTypeKind> = {
    csv: 'spreadsheet',
    doc: 'document',
    docx: 'document',
    gz: 'archive',
    jpeg: 'image',
    jpg: 'image',
    key: 'presentation',
    md: 'text',
    numbers: 'spreadsheet',
    pages: 'document',
    pdf: 'pdf',
    png: 'image',
    ppt: 'presentation',
    pptx: 'presentation',
    rar: 'archive',
    svg: 'image',
    tar: 'archive',
    txt: 'text',
    xls: 'spreadsheet',
    xlsx: 'spreadsheet',
    zip: 'archive',
};

function extensionFromName(name?: string | null): string {
    if (!name || !name.includes('.')) {
        return '';
    }

    return name.split('.').pop()?.toLowerCase() ?? '';
}

function labelFromMimeType(mimeType?: string | null): string {
    if (!mimeType) {
        return '';
    }

    const [group, subtype = ''] = mimeType.split('/');
    const cleanSubtype = subtype
        .replace(/^vnd\./, '')
        .replace(/[.+-]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    if (!group || !cleanSubtype) {
        return '';
    }

    return `${cleanSubtype
        .split(' ')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ')} ${group === 'application' ? 'file' : group}`;
}

export function fileTypeKind(
    name?: string | null,
    mimeType?: string | null,
): FileTypeKind {
    const extension = extensionFromName(name);

    if (mimeType?.startsWith('image/')) {
        return 'image';
    }

    if (mimeType?.startsWith('audio/')) {
        return 'audio';
    }

    if (mimeType?.startsWith('video/')) {
        return 'video';
    }

    if (mimeType === 'application/pdf') {
        return 'pdf';
    }

    if (mimeType?.includes('spreadsheet') || mimeType?.includes('excel')) {
        return 'spreadsheet';
    }

    if (
        mimeType?.includes('presentation') ||
        mimeType?.includes('powerpoint')
    ) {
        return 'presentation';
    }

    if (mimeType?.includes('zip') || mimeType?.includes('compressed')) {
        return 'archive';
    }

    if (mimeType?.startsWith('text/')) {
        return codeExtensions.has(extension) ? 'code' : 'text';
    }

    if (codeExtensions.has(extension)) {
        return 'code';
    }

    return extensionKinds[extension] ?? 'unknown';
}

export function formatFileType(
    name?: string | null,
    mimeType?: string | null,
): string {
    const extension = extensionFromName(name);

    if (extensionLabels[extension]) {
        return extensionLabels[extension];
    }

    if (codeExtensions.has(extension)) {
        return `${extension.toUpperCase()} code file`;
    }

    const mimeLabel = labelFromMimeType(mimeType);

    if (mimeLabel) {
        return mimeLabel;
    }

    return extension ? `${extension.toUpperCase()} file` : 'File';
}
