const esc = (v) => {
    const s = String(v ?? '');
    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

/** Plain-text details for a row in a given tab (shared by the table and the CSV). */
export function detailsFor(tab, r) {
    switch (tab) {
        case 'broken': return (r.broken || []).map((b) => `${b.target_url}${b.anchor ? ` (${b.anchor})` : ''}`).join('; ');
        case 'repeats': return (r.repeats || []).map((x) => `${x.target_url} x${x.count}`).join('; ');
        case 'self': return (r.self || []).join('; ');
        default: return '';
    }
}

export function buildCsv(tab, rows) {
    const lines = [['Title', 'Type', 'URL', 'Details'].map(esc).join(',')];
    rows.forEach((r) => lines.push([r.title, r.type_label || r.type, r.url, detailsFor(tab, r)].map(esc).join(',')));
    return '﻿' + lines.join('\r\n');
}

export function downloadCsv(name, text) {
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
