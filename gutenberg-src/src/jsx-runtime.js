/**
 * Minimal `react/jsx-runtime` replacement built on wp.element.
 *
 * WordPress only registers a `react-jsx-runtime` script handle from 6.6, but the
 * plugin supports 5.6+, and bundling the real package is not allowed on WordPress.org.
 * `jsxs` receives static children arrays, so they are spread (no key warnings);
 * `jsx` passes a single child through unchanged.
 */
import { createElement, Fragment } from '@wordpress/element';

export { Fragment };

export function jsx( type, props, key ) {
	const { children, ...rest } = props || {};
	if ( key !== undefined ) {
		rest.key = key;
	}
	return children === undefined
		? createElement( type, rest )
		: createElement( type, rest, children );
}

export function jsxs( type, props, key ) {
	const { children, ...rest } = props || {};
	if ( key !== undefined ) {
		rest.key = key;
	}
	return Array.isArray( children )
		? createElement( type, rest, ...children )
		: createElement( type, rest, children );
}

export const jsxDEV = jsx;
