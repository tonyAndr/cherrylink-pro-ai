export default function Tabs({ tabs, current, onChange }) {
    return (
        <div className="cl-tabs" role="tablist">
            {tabs.map((t) => (
                <button key={t.id} type="button" role="tab" aria-selected={current === t.id} onClick={() => onChange(t.id)}>
                    {t.label}
                    {t.badge ? <span className="cl-n">{t.badge}</span> : null}
                </button>
            ))}
        </div>
    );
}
