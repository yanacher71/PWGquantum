# Cable measurements

Deploy the new PHP, JS and CSS files alongside the updated cable and admin pages. No database migration is needed. The PHP user must be able to create `pwg-measurements` beside `pwg-db-config.php`, outside the public document root. This follows the existing report storage layout.

In Cable Admin choose **Measurements** beside a saved cable. Supply measurement conditions, including date, reference plane, end assignments, time gating and whether the connectors are included. An import publishes the available files and replaces the complete previous set atomically. Zero-byte files are omitted. Missing or invalid calibration prevents a TDR import. Existing published data survives failed imports.

## Upload

Upload the original `tdr.txt`, `s11.s1p` and/or `s22.s1p` through their labeled fields. A TDR import also requires `50ohms.txt`, `open.txt` and `short.txt`. Each file is limited to 4 MB. Ensure PHP `post_max_size` accommodates the combined upload and `upload_max_filesize` accommodates individual files.

## Drive import

Enable the Google Drive API in the server's Google Cloud project. Add one of these entries to the existing configuration array in `pwg-db-config.php` outside the public directory.

- `google_drive_api_key` for folders and files shared with anyone who has the link. Restrict the key to the Drive API and the hosting server's IP where supported.
- `google_drive_access_token` for an OAuth access token with read access to the selected files. Tokens expire and must be refreshed outside this application.

The ChatGPT Drive connection does not configure the website's server credentials. Upload works without Drive credentials. PHP cURL is required only for Drive import.

Enter the individual cable folder and calibration folder URLs or IDs. The importer saves those IDs with the measurement record. Files must have the exact names above and be direct children of the selected folders. Duplicate filenames are rejected. Re-import after changing source measurements. Customer visits do not contact Google Drive.

## Bulk import

Choose **Bulk measurement import** in Cable Admin and enter the parent CABLETESTS folder URL. The importer matches direct child folders to existing cable serial numbers, reads each folder's measurement_conditions.txt, and automatically finds the shared calibration folder. An optional calibration folder URL overrides automatic discovery.

The page imports one cable per request and reports imported, skipped or failed results. Keep the page open through completion. Pause stops after the current cable, and Resume continues the same queue. A network interruption can be retried with Resume. Each run snapshots the shared calibration once. An expired session requires a new scan.

Zero-byte placeholders and unchanged records are skipped. Successful imports replace the entire published measurement set and conditions for that cable. Failed imports retain the previous record. Duplicate serial folders are rejected, and folders without matching database records are skipped. Only available measurements are published, so replacing a populated source file with an empty file removes that plot on a subsequent successful import that has other populated measurements. Entirely empty folders never erase existing records.

## Conversion and presentation

TDR uses the mean of each reference over 0.6–1.8 ns. The load must agree with the open/short midpoint within 2% of the open/load span. With `span = Vopen - V50`, `rho = (V - V50) / span` and `Z = 50 * (1 + rho) / (1 - rho)`. Singular open-circuit points are gaps. This constant normalization assumes the references apply to the cable acquisition. It does not perform full fixture de-embedding or loss compensation. The raw reference files, voltage levels and averaging interval are retained with each import.

Customer plots use a common initial TDR range of 30–70 ohms, a dashed 50 ohm line, drag-to-zoom and an impedance fit button. Reflection plots use a common −80–0 dB range with measured points. Hover/touch shows actual values. Files outside those display ranges remain in the downloads. The original S22 filename identifies the opposite cable end even though a one-port instrument export labels its internal parameter S11.

Both the page and raw-file endpoint require the existing public cable token or an authenticated admin serial lookup. Measurement files and references are not committed to the repository.
