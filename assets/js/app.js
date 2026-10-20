const shell = document.querySelector('.app-shell');
const toggle = document.getElementById('menuToggle');

// ---- Sidebar collapse ----
toggle.addEventListener('click', function () {
    shell.classList.toggle('sidebar-collapsed');
});

// ---- Popups (dialogs) ----

// Any button with data-open-dialog="someId" opens that dialog
document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById(button.dataset.openDialog).showModal();
    });
});

// Any button with data-close-dialog closes the dialog it sits inside
document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
        button.closest('dialog').close();
    });
});

// Clicking the dark area outside the popup closes it
document.querySelectorAll('dialog').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

// A dialog marked data-auto-open opens as soon as the page loads (used after errors)
document.querySelectorAll('dialog[data-auto-open]').forEach(function (dialog) {
    dialog.showModal();
});

// ---- Photo preview helpers ----

// Show a picture in a preview box (url), or show the "no photo" text (empty url)
function setPreview(img, url) {
    const emptyText = img.nextElementSibling;

    if (img.dataset.url) {
        URL.revokeObjectURL(img.dataset.url); // free the memory of a previous local preview
        delete img.dataset.url;
    }

    if (url) {
        img.src = url;
        img.hidden = false;
        emptyText.hidden = true;
    } else {
        img.removeAttribute('src');
        img.hidden = true;
        emptyText.hidden = false;
    }
}

// When a file is chosen, preview it before it is uploaded
document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
    const img = document.getElementById(input.dataset.preview);

    input.addEventListener('change', function () {
        let file = input.files[0];

        // Browser-side size check (the server checks again)
        if (file && file.size > 2 * 1024 * 1024) {
            alert('Image must be 2 MB or smaller.');
            input.value = '';
            file = null;
        }

        if (file) {
            const url = URL.createObjectURL(file);
            setPreview(img, url);
            img.dataset.url = url;
        } else {
            // No new file: go back to the item's current photo (if it has one)
            setPreview(img, img.dataset.original || '');
        }
    });
});

// ---- Edit popup: copy the clicked row's data into the form, then open it ----
// Every data-xxx attribute on the button fills the field named "xxx" in the popup.
const editDialog = document.getElementById('editDialog');

if (editDialog) {
    document.querySelectorAll('[data-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            Object.keys(button.dataset).forEach(function (key) {
                if (key === 'edit' || key === 'previewUrl') {
                    return;
                }
                const field = editDialog.querySelector('[name="' + key + '"]');
                if (field) {
                    field.value = button.dataset[key];
                }
            });

            // Passwords and chosen files never carry over from a previous edit
            editDialog.querySelectorAll('input[type="password"], input[type="file"]').forEach(function (field) {
                field.value = '';
            });

            // Photo preview: show this item's current photo, if there is one
            const img = editDialog.querySelector('.preview-box img');
            if (img) {
                const current = button.dataset.previewUrl || '';
                img.dataset.original = current;
                setPreview(img, current);
            }

            editDialog.showModal();
        });
    });
}

// ---- Delete popup: show the clicked row's name, remember its id ----
const deleteDialog = document.getElementById('deleteDialog');

if (deleteDialog) {
    document.querySelectorAll('[data-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            deleteDialog.querySelector('[name="id"]').value = button.dataset.id;
            deleteDialog.querySelector('#deleteName').textContent = button.dataset.name;
            deleteDialog.showModal();
        });
    });
}

// ---- Restock popup: show the ingredient's name and unit, remember its id ----
const restockDialog = document.getElementById('restockDialog');

if (restockDialog) {
    document.querySelectorAll('[data-restock]').forEach(function (button) {
        button.addEventListener('click', function () {
            restockDialog.querySelector('[name="id"]').value = button.dataset.id;
            restockDialog.querySelector('[name="amount"]').value = '';
            restockDialog.querySelector('#restockName').textContent = button.dataset.name;
            restockDialog.querySelector('#restockUnit').textContent = button.dataset.unit;
            restockDialog.showModal();
        });
    });
}

// ---- Order screen: quantity buttons, live summary, menu search ----
const orderForm = document.getElementById('orderForm');

if (orderForm) {
    const rows = Array.from(orderForm.querySelectorAll('.menu-row'));
    const groups = Array.from(orderForm.querySelectorAll('.menu-group'));
    const summaryList = document.getElementById('summaryList');
    const summaryTotal = document.getElementById('summaryTotal');
    const summaryEmpty = document.getElementById('summaryEmpty');
    const sendButton = document.getElementById('sendOrder');
    const search = document.getElementById('menuSearch');

    // Reads a quantity without changing what the person typed
    function readQty(input) {
        const n = parseInt(input.value, 10);
        if (isNaN(n) || n < 0) {
            return 0;
        }
        return Math.min(n, 99);
    }

    function updateSummary() {
        summaryList.innerHTML = '';
        let total = 0;
        let count = 0;

        rows.forEach(function (row) {
            const qty = readQty(row.querySelector('.qty-input'));
            row.classList.toggle('is-picked', qty > 0);

            if (qty > 0) {
                const amount = parseFloat(row.dataset.price) * qty;
                total += amount;
                count += qty;

                const li = document.createElement('li');
                const label = document.createElement('span');
                const price = document.createElement('span');
                label.textContent = qty + ' \u00d7 ' + row.dataset.name;
                price.textContent = amount.toFixed(2);
                li.append(label, price);
                summaryList.appendChild(li);
            }
        });

        summaryTotal.textContent = 'Rs. ' + total.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        summaryEmpty.hidden = count > 0;
        sendButton.disabled = count === 0;
    }

    // + and - buttons
    orderForm.addEventListener('click', function (event) {
        const button = event.target.closest('.qty-minus, .qty-plus');
        if (!button) {
            return;
        }
        const input = button.closest('.menu-row').querySelector('.qty-input');
        const change = button.classList.contains('qty-plus') ? 1 : -1;
        input.value = Math.max(0, Math.min(99, readQty(input) + change));
        updateSummary();
    });

    // Typing a quantity
    orderForm.addEventListener('input', function (event) {
        if (event.target.classList.contains('qty-input')) {
            updateSummary();
        }
    });

    // When a quantity field is left, tidy up what was typed ("abc" becomes 0)
    orderForm.addEventListener('change', function (event) {
        if (event.target.classList.contains('qty-input')) {
            event.target.value = readQty(event.target);
            updateSummary();
        }
    });

    // Enter must not send the order by accident
    orderForm.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
            event.preventDefault();
        }
    });

    // Menu search: hide dishes that do not match (hidden dishes stay in the order)
    search.addEventListener('input', function () {
        const query = search.value.trim().toLowerCase();

        rows.forEach(function (row) {
            row.hidden = query !== '' && !row.dataset.name.toLowerCase().includes(query);
        });
        groups.forEach(function (group) {
            group.hidden = !group.querySelector('.menu-row:not([hidden])');
        });
    });

    updateSummary();
}

// ---- Cancel-order popup: show the order, remember its id ----
const cancelDialog = document.getElementById('cancelDialog');

if (cancelDialog) {
    document.querySelectorAll('[data-cancel]').forEach(function (button) {
        button.addEventListener('click', function () {
            cancelDialog.querySelector('[name="id"]').value = button.dataset.id;
            cancelDialog.querySelector('#cancelName').textContent = button.dataset.name;
            cancelDialog.showModal();
        });
    });
}

// ---- Bill popup: live preview of discount, VAT and total ----
document.querySelectorAll('form[data-bill]').forEach(function (form) {
    const subtotalCents = Math.round(parseFloat(form.dataset.subtotal) * 100);
    const vatRate = parseFloat(form.dataset.vat);
    const input = form.querySelector('[name="discount"]');

    function money(cents) {
        return (cents / 100).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function update() {
        let pct = parseFloat(input.value);
        if (isNaN(pct) || pct < 0) {
            pct = 0;
        }
        if (pct > 100) {
            pct = 100;
        }

        const discountCents = Math.round(subtotalCents * pct / 100);
        const afterCents = subtotalCents - discountCents;
        const vatCents = Math.round(afterCents * vatRate / 100);

        form.querySelector('.bill-discount').textContent = money(discountCents);
        form.querySelector('.bill-vat').textContent = money(vatCents);
        form.querySelector('.bill-total').textContent = money(afterCents + vatCents);
    }

    input.addEventListener('input', update);
    update();
});