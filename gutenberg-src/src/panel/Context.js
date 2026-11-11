/** Render "text [phrase] text" with the bracketed phrase marked. */
export default function Context({ text, link }) {
    const m = /\[(.+?)\]/.exec(text || '');
    if (!m) return <p className="cl-ctx">{text}</p>;
    const before = text.slice(0, m.index);
    const after = text.slice(m.index + m[0].length);
    return (
        <p className="cl-ctx">
            {before}
            {link ? <a href="#" onClick={(e) => e.preventDefault()}>{m[1]}</a> : <mark>{m[1]}</mark>}
            {after}
        </p>
    );
}
