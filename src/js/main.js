// --- Global variables for storing data from PHP ---
let currentLocale = null;
let lang = {};
let isAdmin = false;
let adminKey = '';

// --- Function to get translation ---
function t(key) {
    if (lang && typeof lang === 'object' && lang[key]) {
        return lang[key];
    }
    return key || '';
}

// --- Main application initialization function ---
function initializeApp() {
    console.log("Starting application initialization...");
    try {
        // --- Step 1: Reading data from data attributes ---
        const $html = $('html'); // Or $('body') if attributes are there

        // Get currentLocale
        currentLocale = $html.data('locale') || 'en'; // Default value
        console.log("currentLocale:", currentLocale);

        // Get isAdmin (convert string to boolean)
        const isAdminStr = $html.data('is-admin');
        isAdmin = (isAdminStr === true || isAdminStr === 'true');
        console.log("isAdmin:", isAdmin);

        // Get adminKey
        adminKey = $html.data('admin-key') || '';
        console.log("adminKey length:", adminKey.length); // Don't log the key itself for security reasons

        // Get lang object from separate script tag
        const langScript = document.getElementById('lang-data');
        if (langScript && langScript.textContent) {
            try {
                lang = JSON.parse(langScript.textContent);
                console.log("lang object loaded.");
                // console.log("lang:", lang); // May be a lot of data
            } catch (e) {
                console.error('Error parsing language data from script#lang-data:', e);
                alert('Error loading language data.');
            }
        } else {
             console.warn('script#lang-data tag with language data not found.');
             alert('Language data not found.');
        }

        console.log("Global variables set.");

        // --- Step 2: Run the rest of initialization ---
        runInitializationScripts();
    } catch (e) {
         console.error('Critical error during initialization:', e);
         alert('Critical error during application initialization.');
    }
}

// --- Function containing all other initialization logic ---
function runInitializationScripts() {
    console.log("Running initialization scripts...");
    try {
        // --- Theme initialization ---
        initializeTheme();

        // --- Language switcher initializer ---
        $('#language-select').dropdown();
        $('#language-select').on('change', function () {
            var value = $(this).val();
            if (value && currentLocale && value !== currentLocale) {
                window.location.search = '?lang=' + encodeURIComponent(value);
            }
        });

        // --- Event handlers ---
        $('#theme-toggle').on('click', function () {
            const isCurrentlyDark = $('body').hasClass('inverted');
            applyTheme(!isCurrentlyDark);
        });

        $('.menu .item').tab();
        $('.ui.dropdown').dropdown();
        $('.ui.checkbox').checkbox();

        $('.message .close').on('click', function () {
            $(this).closest('.message').transition('fade');
        });

        $('#showCreateForm').click(function () {
            $('#createLicenseForm').show();
            $('#showCreateForm_div').hide();
        });

        $('#cancelCreateLicense').click(function () {
            $('#createLicenseForm').hide();
            $('#showCreateForm_div').show();
            $('#licenseCreationForm')[0].reset();
            $('#createResult').empty();
        });

        $('#licenseCreationForm').submit(function (e) {
             e.preventDefault();
             const userEmail = $(this).find('input[name="user"]').val().trim();
             if (!userEmail) {
                 $('#createResult').html(`
                     <div class="ui negative message">
                         <i class="close icon"></i>
                         <div class="header">
                             <i class="times circle icon"></i>
                             ${t('validation_error_title')}
                         </div>
                         <p>${t('user_email_required')}</p>
                     </div>
                 `);
                 $('#createResult').show();
                 $('.message .close').on('click', function () { $(this).closest('.message').transition('fade'); });
                 return;
             }
             const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
             if (!emailRegex.test(userEmail)) {
                 $('#createResult').html(`
                     <div class="ui negative message">
                         <i class="close icon"></i>
                         <div class="header">
                             <i class="times circle icon"></i>
                             ${t('validation_error_title')}
                         </div>
                         <p>${t('invalid_email')}</p>
                     </div>
                 `);
                 $('#createResult').show();
                 $('.message .close').on('click', function () { $(this).closest('.message').transition('fade'); });
                 return;
             }
             submitForm('/api', $(this), 'POST', '#createResult');
        });

        $('#refreshLogsBtn').click(function () { refreshLogs(1); });
        $('#logOperationFilter').on('change', function () { refreshLogs(1); });
        $('#applyFilters').click(function () { applyFilters(1); });

        $('#sendTestEmailBtn').on('click', function () {
             const $btn = $(this);
             const $logArea = $('#emailTestLog');
             const $logContent = $('#emailTestLogContent');
             $btn.addClass('loading disabled');
             $logArea.show();
             $logContent.text(t('email_sending') + '\n');
             $.ajax({
                 url: '/api',
                 method: 'POST',
                 data: { action: 'test-email', admin_key: adminKey },
                 dataType: 'json',
                 success: function (data) {
                     $btn.removeClass('loading disabled');
                     if (data.success) {
                         $logContent.append(t('email_success_prefix') + data.message + '\n');
                         if (data.details) { $logContent.append(`Details: ${JSON.stringify(data.details, null, 2)}\n`); }
                     } else { $logContent.append(t('email_error_prefix') + data.error + '\n'); }
                 },
                 error: function (xhr, status, error) {
                     $btn.removeClass('loading disabled');
                     $logContent.append(t('email_http_error_prefix') + status + ' - ' + error + '\n');
                     if (xhr.responseText) { $logContent.append(t('email_response_prefix') + xhr.responseText + '\n'); }
                 }
             });
        });

        $(window).on('resize', function () {
            clearTimeout(window.resizeTimer);
            window.resizeTimer = setTimeout(updateScrollOverlays, 100);
        });

        $('#licenseSearch').keypress(function (e) {
            if (e.which === 13) { applyFilters(1); }
        });

        // --- Loading initial data ---
        if (isAdmin) {
            console.log("User is authenticated, loading data...");
            setTimeout(function () {
                refreshLicenses(1);
                refreshLogs(1);
            }, 500);
        } else {
            console.log("User is not authenticated, skipping data loading.");
        }

        // --- Sorting ---
        $(document).on('click', 'th.sortable', function () {
             const $this = $(this);
             const sortColumn = $this.data('sort');
             const $tbody = $('#licenseTableBody');
             const currentSortColumn = $tbody.data('sort-column');
             const currentSortDirection = $tbody.data('sort-direction') || 'asc';
             let newSortDirection = 'asc';
             if (currentSortColumn === sortColumn && currentSortDirection === 'asc') { newSortDirection = 'desc'; }
             $tbody.data('sort-column', sortColumn);
             $tbody.data('sort-direction', newSortDirection);
             $('th.sortable').removeClass('sorted ascending descending');
             $this.addClass('sorted ' + (newSortDirection === 'asc' ? 'ascending' : 'descending'));
             const currentPage = parseInt($('#licensesPagination .active').text()) || 1;
             refreshLicenses(currentPage);
        });

        console.log("Initialization completed successfully.");
    } catch (e) {
         console.error('Error during script initialization:', e);
         alert('An error occurred during interface initialization.');
    }
}

// --- All other functions (initializeTheme, applyTheme, updateScrollOverlays, applyFilters,
// refreshLicenses, displayLicenses, renderLicensesPagination, refreshLogs, displayLogs,
// renderLogsPagination, submitForm, displayResult, deleteLicense, validateLicense, activateLicense)
// remain UNCHANGED, as in the previous example (AJAX), BUT:
// 1. They use global variables currentLocale, lang, isAdmin, adminKey,
//    which are now set by the initializeApp function through data attributes.
// 2. The t(key) function also uses the global lang object.
// 3. In functions where PHP `htmlspecialchars($adminKey)` or
//    variable from AJAX was used before, we now use the JavaScript variable `adminKey`.
//    Examples of such places:
//    - In refreshLicenses, refreshLogs, deleteLicense, validateLicense, activateLicense:
//      WAS: const adminKey = '<?php echo htmlspecialchars($adminKey); ?>';
//      NOW: (adminKey variable is already available globally, just use it)
//      const data = 'action=list&admin_key=' + encodeURIComponent(adminKey) + ...

// --- Place ALL OTHER FUNCTIONS from your previous main.js (AJAX version) WITHOUT CHANGES ---
// (Except for the definitions of variables currentLocale, lang, isAdmin, adminKey, function t,
// initializeApp and runInitializationScripts)

// --- Functions for working with theme ---
function initializeTheme() {
    const savedTheme = localStorage.getItem('darkTheme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = savedTheme !== null ? JSON.parse(savedTheme) : prefersDark;
    applyTheme(isDark);
}

function applyTheme(isDark) {
    if (isDark) {
        $('body').addClass('inverted');
        $('.ui.segment, .ui.card, .ui.menu, .ui.tab, .ui.form, .ui.table, .ui.header, .ui.button, .ui.input, .ui.dropdown, .ui.pagination, .ui.message, .ui.feed, .ui.list')
            .addClass('inverted');
        $('#theme-toggle').html('<i class="sun icon"></i>');
    } else {
        $('body').removeClass('inverted');
        $('.ui.segment, .ui.card, .ui.menu, .ui.tab, .ui.form, .ui.table, .ui.header, .ui.button, .ui.input, .ui.dropdown, .ui.pagination, .ui.message, .ui.feed, .ui.list')
            .removeClass('inverted');
        $('#theme-toggle').html('<i class="moon icon inverted"></i>');
    }
    localStorage.setItem('darkTheme', isDark);
}

function updateScrollOverlays() {
    $('.license-table-wrapper').each(function () {
        const $wrapper = $(this);
        const $container = $wrapper.closest('.scrollable-table-container');
        const scrollLeft = $wrapper.scrollLeft();
        const scrollWidth = $wrapper[0].scrollWidth;
        const clientWidth = $wrapper[0].clientWidth;
        if (scrollLeft > 0) {
            $container.addClass('can-scroll-left');
        } else {
            $container.removeClass('can-scroll-left');
        }
        if (scrollLeft + clientWidth < scrollWidth) {
            $container.addClass('can-scroll-right');
        } else {
            $container.removeClass('can-scroll-right');
        }
    });
}

// --- Functions for working with licenses ---
function applyFilters(page = 1) {
    refreshLicenses(page);
}

function refreshLicenses(page = 1) {
    // Use global adminKey variable
    const searchTerm = $('#licenseSearch').val();
    const statusFilter = $('#statusFilter').val();
    const limit = parseInt($('#licensesPerPage').val()) || 20;
    let data = 'action=list&admin_key=' + encodeURIComponent(adminKey) +
        '&page=' + page +
        '&limit=' + limit;
    if (searchTerm) {
        data += '&search=' + encodeURIComponent(searchTerm);
    }
    if (statusFilter) {
        data += '&status=' + encodeURIComponent(statusFilter);
    }
    $.ajax({
        url: '/api',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function (data) {
            displayLicenses(data, searchTerm, statusFilter);
            renderLicensesPagination(data, page);
        },
        error: function (xhr, status, error) {
            $('#licenseTableBody').html(`
                <tr>
                    <td colspan="8" class="ui negative message">
                        <i class="close icon"></i>
                        <div class="header">${t('licenses_error_loading')}</div>
                        <p>${error || t('licenses_error_generic')}</p>
                    </td>
                </tr>
            `);
            $('.message .close').on('click', function () {
                $(this).closest('.message').transition('fade');
            });
            $('#licensesPagination').empty();
        }
    });
}

function displayLicenses(data, searchTerm = '', statusFilter = '') {
    const $tbody = $('#licenseTableBody');
    $tbody.empty();
    if (data.success && data.licenses) {
        let licenses = Object.keys(data.licenses).map(key => ({
            key: key,
            ...data.licenses[key]
        }));
        const licenseCount = licenses.length;
        if (licenseCount > 0) {
            const sortColumn = $tbody.data('sort-column') || 'key';
            const sortDirection = $tbody.data('sort-direction') || 'asc';
            licenses.sort((a, b) => {
                let valA, valB;
                switch (sortColumn) {
                    case 'key': valA = a.key; valB = b.key; break;
                    case 'user': valA = a.user || ''; valB = b.user || ''; break;
                    case 'product': valA = a.product || ''; valB = b.product || ''; break;
                    case 'status':
                        const isExpiredA = a.expires && new Date(a.expires) < new Date();
                        const isActiveA = a.activated && !isExpiredA;
                        const statusA = isExpiredA ? 'expired' : (isActiveA ? 'active' : 'inactive');
                        const isExpiredB = b.expires && new Date(b.expires) < new Date();
                        const isActiveB = b.activated && !isExpiredB;
                        const statusB = isExpiredB ? 'expired' : (isActiveB ? 'active' : 'inactive');
                        valA = statusA; valB = statusB; break;
                    case 'created': valA = a.created || ''; valB = b.created || ''; break;
                    case 'expires': valA = a.expires || ''; valB = b.expires || ''; break;
                    default: valA = a.key; valB = b.key;
                }
                if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
            licenses.forEach(license => {
                const isExpired = license.expires && new Date(license.expires) < new Date();
                const isActive = license.activated && !isExpired;
                const statusClass = isExpired ? 'status-expired' : (license.activated ? 'status-active' : 'status-inactive');
                const statusText = isExpired ? t('licenses_status_expired') : (license.activated ? t('licenses_status_active') : t('licenses_status_inactive'));
                const row = `
                    <tr>
                        <td><code>${license.key}</code></td>
                        <td>${license.user}</td>
                        <td>${license.ip_address || t('n_a')}</td>
                        <td>${license.product}</td>
                        <td><span class="${statusClass}">${statusText}</span></td>
                        <td>${license.created}</td>
                        <td>${license.expires || t('never')}</td>
                        <td>
                            <div class="ui buttons">
                                <button class="ui mini red button delete-license-btn" data-key="${license.key}" title="${t('action_delete_title')}">
                                    <i class="trash icon"></i> ${t('licenses_action_delete')}
                                </button>
                                <button class="ui mini primary button validate-license-btn" data-key="${license.key}" title="${t('action_validate_title')}">
                                    <i class="check icon"></i> ${t('licenses_action_validate')}
                                </button>
                                <button class="ui mini green button activate-license-btn" data-key="${license.key}" title="${t('action_activate_title')}">
                                    <i class="play icon"></i> ${t('licenses_action_activate')}
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
                $tbody.append(row);
            });
            if ($('body').hasClass('inverted')) {
                $tbody.find('.ui.buttons').addClass('inverted');
                $tbody.find('.ui.button').addClass('inverted');
            }
            $('.delete-license-btn').off('click').on('click', function () {
                const key = $(this).data('key');
                deleteLicense(key);
            });
            $('.validate-license-btn').off('click').on('click', function () {
                const key = $(this).data('key');
                validateLicense(key);
            });
            $('.activate-license-btn').off('click').on('click', function () {
                const key = $(this).data('key');
                activateLicense(key);
            });
            $('.license-table-wrapper').off('scroll.licenseTable');
            $('.license-table-wrapper').on('scroll.licenseTable', function () {
                const $this = $(this);
                clearTimeout($this.data('scrollTimer'));
                $this.data('scrollTimer', setTimeout(function () {
                    updateScrollOverlays();
                }, 50));
            });
            setTimeout(updateScrollOverlays, 0);
        } else {
            $tbody.append(`
                <tr>
                    <td colspan="8" class="center aligned">
                        <div class="ui info message">
                            <i class="info circle icon"></i>
                            ${t('licenses_no_results')}
                        </div>
                    </td>
                </tr>
            `);
        }
    } else {
        $tbody.append(`
            <tr>
                <td colspan="8" class="center aligned">
                    <div class="ui negative message">
                        <i class="close icon"></i>
                        <div class="header">${t('result_error_title')}</div>
                        <p>${data.error || t('licenses_error_generic')}</p>
                    </div>
                </td>
            </tr>
        `);
        $('.message .close').on('click', function () {
            $(this).closest('.message').transition('fade');
        });
    }
}

function renderLicensesPagination(data, currentPage) {
    const $pagination = $('#licensesPagination');
    const $container = $('.licensesPaginationContainer');
    $pagination.empty();
    if (data.success && data.pages > 1) {
        $container.show();
        if (currentPage > 1) {
            $pagination.append(`
                <a class="icon item" data-page="${currentPage - 1}">
                    <i class="left chevron icon"></i>
                </a>
            `);
        }
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(data.pages, currentPage + 2);
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = (i === currentPage) ? 'active' : '';
            $pagination.append(`
                <a class="item ${activeClass}" data-page="${i}">${i}</a>
            `);
        }
        if (currentPage < data.pages) {
            $pagination.append(`
                <a class="icon item" data-page="${currentPage + 1}">
                    <i class="right chevron icon"></i>
                </a>
            `);
        }
        $pagination.find('a.item').off('click').on('click', function () {
            const page = parseInt($(this).data('page'));
            if (page && page !== currentPage) {
                refreshLicenses(page);
            }
        });
    } else {
        $container.hide();
    }
}

function deleteLicense(key) {
    if (confirm(t('licenses_delete_confirm') + key + '?')) {
        // Use global adminKey variable
        const data = 'key=' + encodeURIComponent(key) + '&action=delete&admin_key=' + encodeURIComponent(adminKey);
        $.ajax({
            url: '/api',
            method: 'POST',
            data: data,
            dataType: 'json',
            success: function (data) {
                if (data.deleted) {
                    alert(t('licenses_delete_success'));
                    const currentPage = parseInt($('#licensesPagination .active').text()) || 1;
                    refreshLicenses(currentPage);
                } else {
                    alert(t('licenses_delete_fail') + (data.reason || 'Unknown error'));
                }
            },
            error: function (xhr, status, error) {
                alert(t('licenses_error_deleting') + error);
            }
        });
    }
}

function validateLicense(key) {
    // Use global adminKey variable
    const data = 'key=' + encodeURIComponent(key) + '&action=validate&admin_key=' + encodeURIComponent(adminKey);
    $.ajax({
        url: '/api',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function (data) {
            if (data.valid) {
                alert(t('licenses_validate_success_valid') + (data.user || t('n_a')) +
                    t('licenses_validate_success_product') + (data.product || t('n_a')));
            } else {
                alert(t('licenses_validate_fail') + (data.reason || 'Unknown'));
            }
        },
        error: function (xhr, status, error) {
            alert(t('licenses_error_validating') + error);
        }
    });
}

function activateLicense(key) {
    // Use global adminKey variable
    const data = 'key=' + encodeURIComponent(key) + '&action=activate&admin_key=' + encodeURIComponent(adminKey);
    $.ajax({
        url: '/api',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function (data) {
            if (data.valid) {
                alert(t('licenses_activate_success'));
                const currentPage = parseInt($('#licensesPagination .active').text()) || 1;
                refreshLicenses(currentPage);
            } else {
                alert(t('licenses_activate_fail') + (data.reason || 'Unknown error'));
            }
        },
        error: function (xhr, status, error) {
            alert(t('licenses_error_activating') + error);
        }
    });
}

// --- Functions for working with logs ---
function refreshLogs(page = 1) {
    // Use global adminKey variable
    const limit = parseInt($('#logsPerPage').val()) || 10;
    const operationFilter = $('#logOperationFilter').val();
    let data = 'action=logs&limit=' + limit + '&admin_key=' + encodeURIComponent(adminKey) + '&page=' + page;
    if (operationFilter) {
        data += '&operation=' + encodeURIComponent(operationFilter);
    }
    $.ajax({
        url: '/api',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function (data) {
            displayLogs(data);
            renderLogsPagination(data, page);
        },
        error: function (xhr, status, error) {
            $('#logsResult').html(`
                <div class="ui negative message">
                    <i class="close icon"></i>
                    <div class="header">${t('logs_error_loading')}</div>
                    <p>${error || t('logs_error_generic')}</p>
                </div>
            `);
            $('.message .close').on('click', function () {
                $(this).closest('.message').transition('fade');
            });
            $('#logsPagination').empty();
            $('.logsPaginationContainer').hide();
        }
    });
}

function renderLogsPagination(data, currentPage) {
    const $pagination = $('#logsPagination');
    const $container = $('.logsPaginationContainer');
    $pagination.empty();
    if (data.success && data.pages > 1) {
        $container.show();
        if (currentPage > 1) {
            $pagination.append(`
                <a class="icon item" data-page="${currentPage - 1}">
                    <i class="left chevron icon"></i>
                </a>
            `);
        }
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(data.pages, currentPage + 2);
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = (i === currentPage) ? 'active' : '';
            $pagination.append(`
                <a class="item ${activeClass}" data-page="${i}">${i}</a>
            `);
        }
        if (currentPage < data.pages) {
            $pagination.append(`
                <a class="icon item" data-page="${currentPage + 1}">
                    <i class="right chevron icon"></i>
                </a>
            `);
        }
        $pagination.find('a.item').off('click').on('click', function () {
            const page = parseInt($(this).data('page'));
            if (page && page !== currentPage) {
                refreshLogs(page);
            }
        });
    } else {
        $container.hide();
    }
}

function displayLogs(data) {
    const $result = $('#logsResult');
    $result.empty();
    if (data.success && data.content) {
        const logCount = data.content.length;
        if (logCount > 0) {
            let html = '<div class="ui feed">';
            data.content.forEach(entry => {
                const actionColors = {
                    'validate': 'blue', 'activate': 'green', 'create': 'positive',
                    'delete': 'red', 'list': 'purple', 'logs_access': 'orange', 'error': 'negative'
                };
                const color = actionColors[entry.action] || 'grey';
                const actionLabel = entry.action.toUpperCase();
                html += `
                    <div class="event">
                        <div class="label">
                            <i class="${entry.action} icon"></i>
                        </div>
                        <div class="content">
                            <div class="summary">
                                <div class="ui ${color} label">${actionLabel}</div>
                                ${entry.ip || t('unknown_ip')}
                                <div class="date">${entry.timestamp}</div>
                            </div>
                            <div class="extra text">
                                ${entry.user_agent ? `<div><strong>User Agent:</strong> ${entry.user_agent}</div>` : ''}
                                ${entry.details ? `<pre>${JSON.stringify(entry.details, null, 2)}</pre>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            $result.html(html);
            if ($('body').hasClass('inverted')) {
                $result.find('.ui.feed').addClass('inverted');
            }
        } else {
            $result.html(`
                <div class="ui info message">
                    <div class="header">
                        <i class="info circle icon"></i>
                        ${t('no_log_entries')}
                    </div>
                    <p>${t('log_file_empty')}</p>
                </div>
            `);
        }
    } else {
        $result.html(`
            <div class="ui negative message">
                <i class="close icon"></i>
                <div class="header">${t('error_title')}</div>
                <p>${data.error || t('error_loading_logs')}</p>
            </div>
        `);
        $('.message .close').on('click', function () {
            $(this).closest('.message').transition('fade');
        });
    }
}

// --- Functions for working with forms and results ---
function submitForm(url, formData, method, resultSelector) {
    const $result = $(resultSelector);
    $result.html('<div class="ui active centered inline loader">' + t('result_processing') + '</div>');
    $result.show();
    $.ajax({
        url: url,
        method: method,
        data: formData.serialize(),
        dataType: 'json',
        success: function (data) {
            displayResult(data, $result);
        },
        error: function (xhr, status, error) {
            $result.html(`
                <div class="ui negative message">
                    <i class="close icon"></i>
                    <div class="header">${t('result_error_title')}</div>
                    <p>${error || t('result_error_generic')}</p>
                    <pre>${xhr.responseText}</pre>
                </div>
            `);
            $('.message .close').on('click', function () {
                $(this).closest('.message').transition('fade');
            });
        }
    });
}

function displayResult(data, $result) {
    let html = '';
    if (data.success) {
        if (data.valid !== undefined) {
            const icon = data.valid ? 'check circle' : 'times circle';
            const color = data.valid ? 'positive' : 'negative';
            const isActivation = data.just_activated !== undefined;
            const title = data.valid ? (isActivation ? t('result_license_activated') : t('result_license_valid')) : t('result_license_invalid');
            html = `
                <div class="ui ${color} message">
                    <i class="close icon"></i>
                    <div class="header">
                        <i class="${icon} icon"></i>
                        ${title}
                    </div>
                    <p>${data.reason || t('result_validation_completed')}</p>
                    ${data.valid ? `
                        <div class="ui list">
                            <div class="item"><strong>${t('result_user')}:</strong> ${data.user || t('n_a')}</div>
                            <div class="item"><strong>${t('result_product')}:</strong> ${data.product || t('n_a')}</div>
                            <div class="item"><strong>${t('result_expires')}:</strong> ${data.expires || t('never')}</div>
                            <div class="item"><strong>${t('result_activated')}:</strong> ${data.activated ? t('yes') : t('no')}</div>
                            ${isActivation ? `<div class="item"><strong>${t('result_just_activated')}:</strong> ${data.just_activated ? t('yes') : t('no')}</div>` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        } else if (data.created !== undefined) {
            html = `
                <div class="ui positive message">
                    <i class="close icon"></i>
                    <div class="header">
                        <i class="check circle icon"></i>
                        ${t('result_created_success')}
                    </div>
                    <p>${t('result_created_message')}</p>
                    <div class="ui list">
                        <div class="item"><strong>${t('result_key')}:</strong> ${data.key}</div>
                        <div class="item"><strong>${t('result_user')}:</strong> ${data.license_info.user}</div>
                        <div class="item"><strong>${t('result_product')}:</strong> ${data.license_info.product}</div>
                        <div class="item"><strong>${t('result_created')}:</strong> ${data.license_info.created}</div>
                        <div class="item"><strong>${t('result_expires')}:</strong> ${data.license_info.expires || t('never')}</div>
                    </div>
                </div>
            `;
            $('#createLicenseForm').hide();
            $('#showCreateForm_div').show();
            $('#licenseCreationForm')[0].reset();
            setTimeout(() => refreshLicenses(1), 1000);
        } else if (data.deleted !== undefined) {
            const title = data.deleted ? t('result_license_deleted') : t('result_license_not_found');
            const message = data.reason || (data.deleted ? t('result_deleted_message') : t('result_not_found_message'));
            html = `
                <div class="ui ${data.deleted ? 'positive' : 'warning'} message">
                    <i class="close icon"></i>
                    <div class="header">
                        <i class="${data.deleted ? 'check' : 'warning'} icon"></i>
                        ${title}
                    </div>
                    <p>${message}</p>
                </div>
            `;
            if (data.deleted) {
                setTimeout(() => {
                    const currentPage = parseInt($('#licensesPagination .active').text()) || 1;
                    refreshLicenses(currentPage);
                }, 1000);
            }
        }
    } else {
        html = `
            <div class="ui negative message">
                <i class="close icon"></i>
                <div class="header">
                    <i class="times circle icon"></i>
                    ${t('error_title_generic')}
                </div>
                <p>${data.error || t('result_error_generic')}</p>
            </div>
        `;
    }
    $result.html(html);
    $('.message .close').on('click', function () {
        $(this).closest('.message').transition('fade');
    });
}