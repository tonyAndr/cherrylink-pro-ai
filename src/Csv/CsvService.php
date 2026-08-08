<?php

declare(strict_types=1);

namespace CherryLink\Csv;

use CherryLink\Ajax\Security;
use CherryLink\Contracts\Registerable;
use WP_Error;

/**
 * Writes and merges the link-scheme CSV exports (Windows-1251 encoded, CRLF line
 * endings, for spreadsheet import). Ported verbatim from the CSV helpers in the
 * legacy cherrylink_statistics.php (now a shim).
 *
 * The per-batch files are written as stats/cherrylink_stats_<offset>.csv; the merge
 * step concatenates them into stats/cherrylink_stats.csv and removes the temp files.
 */
final class CsvService implements Registerable
{
    public function register(): void
    {
        \add_action('wp_ajax_linkate_merge_csv_files', [$this, 'ajaxMerge']);
    }

    /**
     * array_walk callback: encode a single cell to Windows-1251 for the CSV.
     *
     * @param mixed      $value passed by reference
     * @param int|string $key
     */
    public static function encodeValue(&$value, $key): void
    {
        if ($value instanceof WP_Error) {
            $value = 'NULL_ERROR';
        } else {
            $value = \iconv('UTF-8', 'Windows-1251', (string) $value);
        }
    }

    /**
     * fputcsv that honors a custom end-of-line (fputcsv always writes "\n").
     *
     * @param resource     $handle
     * @param array<mixed> $array
     * @return int|false
     */
    public static function fputcsvEol($handle, array $array, string $delimiter = ',', string $enclosure = '"', string $eol = "\n")
    {
        $return = \fputcsv($handle, $array, $delimiter, $enclosure);
        if ($return !== false && "\n" !== $eol && 0 === \fseek($handle, -1, SEEK_CUR)) {
            \fwrite($handle, $eol);
        }
        return $return;
    }

    /**
     * Write one batch of rows to stats/<filename>.
     *
     * @param array<int,array<int,mixed>> $array
     * @param string                      $direction 'outgoing'|'incoming' — column header labels
     */
    public static function queryToCsv(array $array, string $filename, string $direction = 'outgoing'): void
    {
        // Column headers are written into a CSV file (not HTML), so use plain __()
        // without escaping — esc_* would corrupt the values.
        $headers = [
            'ID',
            \__('Тип', 'cherrylink'),
            $direction === 'outgoing' ? \__('Рубрики_источника', 'cherrylink') : \__('Рубрики_цели', 'cherrylink'),
            $direction === 'outgoing' ? \__('URL_источника', 'cherrylink')     : \__('URL_цели', 'cherrylink'),
            $direction === 'outgoing' ? \__('URL_цели', 'cherrylink')          : \__('URL_источника', 'cherrylink'),
            \__('Анкор', 'cherrylink'),
            \__('Исходящих_ссылок', 'cherrylink'),
            \__('Входящих_ссылок', 'cherrylink'),
        ];

        if (! \file_exists(CHERRYLINK_DIR . 'stats')) {
            \mkdir(CHERRYLINK_DIR . 'stats', 0755, true);
        }

        $fp = \fopen(CHERRYLINK_DIR . 'stats/' . $filename, 'w');
        \array_walk($headers, [self::class, 'encodeValue']);
        self::fputcsvEol($fp, $headers, ',', '"', "\r\n");

        foreach ($array as $row) {
            \array_walk($row, [self::class, 'encodeValue']);
            self::fputcsvEol($fp, $row, ',', '"', "\r\n");
        }

        \fclose($fp);
    }

    public function ajaxMerge(): void
    {
        Security::verify('edit_posts');

        $directory     = CHERRYLINK_DIR . 'stats/*';
        $masterCSVFile = \fopen(CHERRYLINK_DIR . 'stats/cherrylink_stats.csv', 'w+');
        $first_file    = true;

        foreach (\glob($directory) as $file) {
            if (\strpos($file, 'cherrylink_stats_') === false) {
                continue;
            }

            $data   = [];
            $handle = \fopen($file, 'r');
            while (($row = \fgetcsv($handle, 1000)) !== false) {
                $data[] = $row;
            }
            \fclose($handle);

            if (! $first_file) {
                unset($data[0]); // skip header on subsequent files
            }
            foreach ($data as $value) {
                try {
                    self::fputcsvEol($masterCSVFile, $value, ',', '"', "\r\n");
                } catch (\Exception $e) {
                    // skip malformed rows
                }
            }
            $first_file = false;
        }

        \fclose($masterCSVFile);
        self::removeOld(true);

        \wp_send_json([
            'status' => 'OK',
            'url'    => CHERRYLINK_DIR_URL . 'stats/cherrylink_stats.csv',
        ]);
    }

    public static function removeOld(bool $only_temp = false): void
    {
        if (! \file_exists(CHERRYLINK_DIR . 'stats')) {
            return;
        }

        foreach (\glob(CHERRYLINK_DIR . 'stats/*') as $file) {
            if (! \is_file($file)) {
                continue;
            }
            if ($only_temp && \strpos($file, 'cherrylink_stats_') !== false) {
                \unlink($file);
            } elseif (! $only_temp) {
                \unlink($file);
            }
        }
    }
}
