import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Toast({ toast, onUndo, onClose }) {
    useEffect(() => {
        if (!toast) return undefined;
        const t = setTimeout(onClose, 4000);
        return () => clearTimeout(t);
    }, [toast]);
    if (!toast) return null;
    return (
        <div className="cl-toast" role="status" aria-live="polite">
            <span>{toast.message}</span>
            {toast.undo && (
                <button type="button" onClick={onUndo}>
                    {__('Отменить', 'cherrylink')}
                </button>
            )}
        </div>
    );
}
