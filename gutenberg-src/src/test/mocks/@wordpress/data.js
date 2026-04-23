// Thin mock — actual behaviour is on the global wp.data set up in setup.js
export const select = (store) => global.wp.data.select(store);
export const dispatch = (store) => global.wp.data.dispatch(store);
export const subscribe = (fn) => global.wp.data.subscribe(fn);
export const useSelect = jest.fn((mapSelect) => mapSelect(global.wp.data.select));
export const useDispatch = jest.fn((store) => global.wp.data.dispatch(store));
