export const __ = (str) => str;
export const _n = (single) => single;
export const _x = (str) => str;
export const sprintf = (fmt, ...args) => {
    let i = 0;
    return String(fmt).replace(/%(?:(\d+)\$)?[sd]/g, (m, n) => String(args[n ? n - 1 : i++]));
};
