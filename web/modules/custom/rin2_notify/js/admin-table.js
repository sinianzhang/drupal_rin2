/**
 * @file
 * Client-side filtering, sorting and paging for RIN2 admin tables.
 *
 * Markup contract (generic; used by the notifications and users lists):
 * - table[data-rin2-table="KEY"]: the table to enhance;
 * - [data-rin2-filters="KEY"]: wrapper around the filter inputs;
 * - inputs inside it with data-rin2-column="N" (0-based column index; for
 *   "contains" also a list like "0,1" to search several columns) and
 *   data-rin2-match="exact" (whole cell text), "list" (one item of a
 *   comma-separated cell), "positive" (value "yes": number > 0, "no": 0) or
 *   "contains" (default);
 * - [data-rin2-filter-reset]: optional button clearing all filters;
 * - th[data-rin2-sortable="false"]: column without sorting (e.g. operations);
 * - td[data-order]: optional sort value (e.g. a timestamp).
 */
((Drupal, once) => {
  'use strict';

  const SOURCE = 'rin2-filters';

  /**
   * Visible text of a simple-datatables cell, normalised for comparison.
   */
  function cellText(cell) {
    if (typeof cell.text === 'string') {
      return cell.text.trim().toLowerCase();
    }
    return String(cell.data ?? '').trim().toLowerCase();
  }

  /**
   * Exact (case-insensitive) match; terms arrive lower-cased.
   */
  function exactMatch(terms, cell) {
    const text = cellText(cell);
    return terms.some((term) => term === text);
  }

  /**
   * Matches one item of a comma-separated cell, e.g. "alice, bob".
   */
  function listMatch(terms, cell) {
    const items = cellText(cell).split(',').map((item) => item.trim());
    return terms.some((term) => items.includes(term));
  }

  /**
   * Matches a count cell: term "yes" for a number > 0, "no" for 0.
   */
  function positiveMatch(terms, cell) {
    const positive = (parseInt(cellText(cell), 10) || 0) > 0;
    return terms.some((term) => (term === 'yes') === positive);
  }

  const MATCHERS = { exact: exactMatch, list: listMatch, positive: positiveMatch };

  /**
   * Column indexes from data-rin2-column, e.g. "2" or "0,1".
   */
  function columnsOf(input) {
    return input.dataset.rin2Column.split(',').map((index) => parseInt(index, 10));
  }

  Drupal.behaviors.rin2AdminTable = {
    attach(context) {
      once('rin2-admin-table', 'table[data-rin2-table]', context).forEach((table) => {
        // Drupal renders an "empty" row when there is no data; nothing to do.
        if (!table.tBodies.length || table.querySelector('td.empty')) {
          return;
        }
        const key = table.dataset.rin2Table;
        const filters = document.querySelector(`[data-rin2-filters="${key}"]`);
        const inputs = filters ? Array.from(filters.querySelectorAll('[data-rin2-column]')) : [];

        // Columns compared with a custom matcher instead of "contains".
        const customColumns = inputs
          .filter((input) => MATCHERS[input.dataset.rin2Match])
          .map((input) => ({
            select: columnsOf(input)[0],
            type: 'string',
            ignorePunctuation: false,
            searchMethod: MATCHERS[input.dataset.rin2Match],
          }));
        // Columns marked as not sortable in the header.
        Array.from(table.tHead?.rows[0]?.cells ?? []).forEach((th, index) => {
          if (th.dataset.rin2Sortable === 'false') {
            customColumns.push({ select: index, sortable: false });
          }
        });

        const dataTable = new window.simpleDatatables.DataTable(table, {
          // Our own filter inputs replace the built-in search box.
          searchable: false,
          perPage: 25,
          perPageSelect: [10, 25, 50, 100],
          columns: customColumns,
          labels: {
            perPage: Drupal.t('per page'),
            noRows: Drupal.t('No entries found'),
            noResults: Drupal.t('No entries match the filters.'),
            info: Drupal.t('Showing {start} to {end} of {rows} entries'),
            sortHint: Drupal.t('Activate to sort'),
          },
        });
        // Columns with data-order (dates, counts) sort by that value; the
        // default view stays the server's order (newest first).

        const apply = () => {
          const queries = inputs
            .filter((input) => input.value.trim() !== '')
            .map((input) => ({
              terms: [input.value.trim()],
              columns: columnsOf(input),
            }));
          dataTable.multiSearch(queries, SOURCE);
        };

        inputs.forEach((input) => {
          input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', apply);
          // Enter in a text field must not submit the (filter-only) form.
          input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
            }
          });
        });

        const reset = filters?.querySelector('[data-rin2-filter-reset]');
        if (reset) {
          reset.addEventListener('click', (event) => {
            event.preventDefault();
            inputs.forEach((input) => {
              input.value = '';
            });
            apply();
          });
        }
        // Re-apply values the browser restored (back button).
        apply();
      });
    },
  };
})(Drupal, once);
