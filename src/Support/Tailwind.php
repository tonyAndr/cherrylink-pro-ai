<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Tailwind utility-class strings shared by the admin views. Moved verbatim from
 * the legacy CL_TWC (now a class_alias shim in cherrylink_tailwind_constants.php).
 */
class Tailwind
{
    /** @var string */
    public static $BTN_NORMAL = "py-2.5 px-5 me-2 my-2 text-sm font-medium text-gray-900 focus:outline-none bg-white rounded-lg border border-gray-200 hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer";

    /** @var string */
    public static $BTN_ACTION = "text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg px-5 py-2.5 me-2 mb-2 dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800 hover:cursor-pointer";

    /** @var string */
    public static $BTN_SCAN = "text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-lg px-5 py-2.5 me-2 mb-2 dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800 hover:cursor-pointer";

    /** @var string */
    public static $BTN_DANGER = "focus:outline-none text-red-700 border border-red-700 hover:bg-red-700 hover:text-white focus:ring-4 focus:ring-red-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 hover:cursor-pointer";

    /** @var string */
    public static $BTN_SAVE = "focus:outline-none text-blue-600 border border-blue-600 hover:bg-blue-700 hover:text-white focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 hover:cursor-pointer";

    /** @var string */
    public static $H1 = "text-3xl font-medium pl-4 my-6";

    /** @var string */
    public static $H2 = "text-lg font-bold border-solid border-l-4 pl-4 border-cyan-800 mb-4";

    /** @var string */
    public static $SUB_H = "";

    /** @var string */
    public static $INP_BASE = "block w-full text-sm text-stone-500 border rounded-lg";

    /** @var string */
    public static $CARD = "p-6 rounded-lg shadow-xl bg-white";

    /** @var string */
    public static $CARD_SPECIAL = "p-6 rounded-lg shadow-xl bg-amber-200";
}
