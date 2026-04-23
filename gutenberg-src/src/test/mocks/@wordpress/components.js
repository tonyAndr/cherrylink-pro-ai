// Render children as-is so component tests can find rendered output
import React from 'react';

export const Button = ({ children, onClick, className }) =>
    <button className={className} onClick={onClick}>{children}</button>;

export const Tooltip = ({ children }) => <>{children}</>;
export const Spinner = () => <span data-testid="spinner" />;
export const TabPanel = ({ children, tabs, onSelect }) => <div>{children(tabs[0])}</div>;
export const CheckboxControl = ({ label, checked, onChange }) =>
    <input type="checkbox" aria-label={label} checked={checked} onChange={(e) => onChange(e.target.checked)} />;
export const TextControl = ({ value, onChange }) =>
    <input value={value} onChange={(e) => onChange(e.target.value)} />;
export const SelectControl = ({ value, options, onChange }) =>
    <select value={value} onChange={(e) => onChange(e.target.value)}>
        {options?.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>;
export const PluginSidebar = ({ children }) => <div data-testid="plugin-sidebar">{children}</div>;
export const PluginSidebarMoreMenuItem = ({ children }) => <div>{children}</div>;
