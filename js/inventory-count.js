/**
 * @file
 * Shows the right follow-up on the inventory count form as the count changes.
 *
 * Above the system count: ask whether it is an unlogged restock or a found
 * correction. Below: say it will be recorded as loss. Purely presentational;
 * InventoryCountForm::validateForm() enforces the choice server-side.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.materialInventoryCount = {
    attach(context) {
      once('material-inventory-count', 'form[data-system-count]', context).forEach((form) => {
        const system = parseInt(form.dataset.systemCount, 10);
        const input = form.querySelector('.js-inventory-count-total');
        const positive = form.querySelector('.js-inventory-count-positive');
        const loss = form.querySelector('.js-inventory-count-loss');
        if (!input || !positive || Number.isNaN(system)) {
          return;
        }
        const legend = positive.querySelector('legend span') || positive.querySelector('legend');
        const description = positive.querySelector('[id$="--wrapper--description"]');

        const update = () => {
          const value = parseInt(input.value, 10);
          const delta = Number.isNaN(value) ? 0 : value - system;
          const hasError = positive.querySelector('.error, .is-invalid, .form-item--error-message, [aria-invalid="true"]');
          positive.hidden = delta <= 0 && !hasError;
          if (legend && delta > 0) {
            legend.textContent = Drupal.t('You counted @delta more than expected. Why?', { '@delta': delta });
          }
          if (description) {
            description.hidden = true;
          }
          if (loss) {
            loss.hidden = delta >= 0;
            if (delta < 0) {
              loss.textContent = Drupal.t('@delta fewer than expected. This will be recorded as loss.', { '@delta': -delta });
            }
          }
        };

        input.addEventListener('input', update);
        update();
      });
    },
  };
})(Drupal, once);
