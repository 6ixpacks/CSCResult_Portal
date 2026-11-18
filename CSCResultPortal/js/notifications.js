// ============================================================
// CSCResultPortal - Notifications JavaScript
// File: js/notifications.js
// Purpose: Handles the notification bell dropdown in topbar.
//          Fetches unread notifications from the API,
//          renders them in the dropdown, handles mark-as-read,
//          and polls for new notifications every 60 seconds.
//
// INCLUDED ON: All dashboard pages via topbar.php
// DEPENDS ON: api/get-notifications.php
//             api/mark-notification-read.php
// ============================================================

'use strict';

// ============================================================
// SECTION 1: ELEMENT REFERENCES
// ============================================================
const notifBellBtn    = document.getElementById('notifBellBtn');
const notifDropdown   = document.getElementById('notifDropdown');
const notifList       = document.getElementById('notifList');
const markAllReadBtn  = document.getElementById('markAllReadBtn');
const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
const portalSidebar   = document.getElementById('portalSidebar');
const sidebarOverlay  = document.getElementById('sidebarOverlay');

// ============================================================
// SECTION 2: NOTIFICATION TYPE CONFIG
// Icon and color for each notification type.
// ============================================================
const notifTypeConfig = {
    scores_submitted:   { icon: 'fa-pen-to-square',       color: '#006400' },
    result_flagged:     { icon: 'fa-flag',                 color: '#dc3545' },
    result_forwarded:   { icon: 'fa-share-from-square',    color: '#ffc107' },
    hod_approved:       { icon: 'fa-circle-check',         color: '#006400' },
    final_approved:     { icon: 'fa-graduation-cap',       color: '#006400' },
    carryover_submitted:{ icon: 'fa-rotate',               color: '#17a2b8' },
    carryover_processed:{ icon: 'fa-rotate-right',         color: '#006400' },
    general:            { icon: 'fa-bell',                 color: '#6c757d' },
};


// ============================================================
// SECTION 3: FETCH NOTIFICATIONS FROM API
// Calls api/get-notifications.php and returns notification array.
// ============================================================

/**
 * Fetches notifications from the server API.
 * @param {number} limit - How many to fetch (default 10)
 * @returns {Promise<Array>}
 */
async function fetchNotifications(limit = 10) {
    try {
        const response = await fetch(
            `${window.APP_URL}/api/get-notifications.php?limit=${limit}`,
            {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }
        );

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        return data.success ? data.notifications : [];

    } catch (error) {
        console.error('Failed to fetch notifications:', error);
        return [];
    }
}


// ============================================================
// SECTION 4: RENDER NOTIFICATION ITEMS
// Builds HTML for each notification and injects into dropdown.
// ============================================================

/**
 * Formats a timestamp as a human-readable relative time.
 * e.g. "2 hours ago", "Just now"
 * @param {string} timestamp - MySQL datetime string
 * @returns {string}
 */
function timeAgo(timestamp) {
    const now      = new Date();
    const past     = new Date(timestamp.replace(' ', 'T'));
    const diffSecs = Math.floor((now - past) / 1000);

    if (diffSecs < 60)     return 'Just now';
    if (diffSecs < 3600)   return `${Math.floor(diffSecs / 60)}m ago`;
    if (diffSecs < 86400)  return `${Math.floor(diffSecs / 3600)}h ago`;
    if (diffSecs < 604800) return `${Math.floor(diffSecs / 86400)}d ago`;

    // Format as date for older items
    return past.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}


/**
 * Renders all notifications into the dropdown list.
 * @param {Array} notifications
 */
function renderNotifications(notifications) {
    if (!notifList) return;

    // Empty state
    if (!notifications || notifications.length === 0) {
        notifList.innerHTML = `
            <div class="notif-empty-state">
                <i class="fa-solid fa-bell-slash"></i>
                <p>No notifications yet</p>
            </div>
        `;
        return;
    }

    // Build notification items HTML
    let html = '';
    notifications.forEach(notif => {
        const config   = notifTypeConfig[notif.type] || notifTypeConfig.general;
        const isUnread = notif.is_read == 0;
        const time     = timeAgo(notif.created_at);

        html += `
            <div class="notif-item ${isUnread ? 'notif-unread' : ''}"
                 data-id="${notif.id}"
                 data-read="${notif.is_read}">
                <div class="notif-item-icon" style="background:${config.color}1a; color:${config.color};">
                    <i class="fa-solid ${config.icon}"></i>
                </div>
                <div class="notif-item-body">
                    <div class="notif-item-title">${escapeHtml(notif.title)}</div>
                    <div class="notif-item-message">${escapeHtml(notif.message)}</div>
                    <div class="notif-item-time">
                        <i class="fa-regular fa-clock fa-xs me-1"></i>${time}
                    </div>
                </div>
                ${isUnread ? `<div class="notif-unread-dot"></div>` : ''}
            </div>
        `;
    });

    notifList.innerHTML = html;

    // Attach click handlers to each item
    notifList.querySelectorAll('.notif-item').forEach(item => {
        item.addEventListener('click', () => handleNotifClick(item));
    });
}


// ============================================================
// SECTION 5: MARK NOTIFICATION AS READ
// ============================================================

/**
 * Marks a single notification as read on click.
 * @param {HTMLElement} item - The notification item element
 */
async function handleNotifClick(item) {
    const notifId  = item.dataset.id;
    const isRead   = item.dataset.read === '1';

    if (isRead) return; // Already read — do nothing

    try {
        const response = await fetch(
            `${window.APP_URL}/api/mark-notification-read.php`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ notification_id: notifId })
            }
        );

        const data = await response.json();

        if (data.success) {
            // Update UI immediately without re-fetching
            item.classList.remove('notif-unread');
            item.dataset.read = '1';

            const dot = item.querySelector('.notif-unread-dot');
            if (dot) dot.remove();

            // Update bell count
            updateBellCount(-1);
        }

    } catch (error) {
        console.error('Failed to mark notification as read:', error);
    }
}


/**
 * Marks ALL unread notifications as read.
 */
async function markAllRead() {
    try {
        const response = await fetch(
            `${window.APP_URL}/api/mark-notification-read.php`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ mark_all: true })
            }
        );

        const data = await response.json();

        if (data.success) {
            // Remove all unread styling
            notifList.querySelectorAll('.notif-item.notif-unread').forEach(item => {
                item.classList.remove('notif-unread');
                item.dataset.read = '1';
                const dot = item.querySelector('.notif-unread-dot');
                if (dot) dot.remove();
            });

            // Reset bell count to 0
            resetBellCount();

            // Hide the mark-all button
            if (markAllReadBtn) {
                markAllReadBtn.style.display = 'none';
            }
        }
    } catch (error) {
        console.error('Failed to mark all as read:', error);
    }
}


// ============================================================
// SECTION 6: BELL COUNT HELPERS
// Update the red count badge on the notification bell.
// ============================================================

/**
 * Gets current bell count from the badge element.
 * @returns {number}
 */
function getCurrentBellCount() {
    const countEl = notifBellBtn?.querySelector('.topbar-notif-count');
    if (!countEl) return 0;
    return parseInt(countEl.textContent.replace('+', '')) || 0;
}

/**
 * Updates bell count by a delta (positive or negative).
 * @param {number} delta
 */
function updateBellCount(delta) {
    const countEl = notifBellBtn?.querySelector('.topbar-notif-count');
    if (!countEl) return;

    const current = getCurrentBellCount();
    const newCount = Math.max(0, current + delta);

    if (newCount === 0) {
        countEl.remove();
    } else {
        countEl.textContent = newCount > 99 ? '99+' : newCount;
    }
}

/**
 * Sets bell count to zero and removes the badge.
 */
function resetBellCount() {
    const countEl = notifBellBtn?.querySelector('.topbar-notif-count');
    if (countEl) countEl.remove();
}


// ============================================================
// SECTION 7: DROPDOWN TOGGLE
// Open/close the notification dropdown panel.
// ============================================================

let dropdownOpen = false;

/**
 * Opens the notification dropdown and loads notifications.
 */
async function openDropdown() {
    if (!notifDropdown) return;

    dropdownOpen = true;
    notifDropdown.classList.add('open');
    notifDropdown.setAttribute('aria-hidden', 'false');

    // Show loading state
    if (notifList) {
        notifList.innerHTML = `
            <div class="notif-loading">
                <span class="spinner-border spinner-border-sm text-success me-2"></span>
                Loading...
            </div>
        `;
    }

    // Fetch and render
    const notifications = await fetchNotifications(10);
    renderNotifications(notifications);
}

/**
 * Closes the notification dropdown.
 */
function closeDropdown() {
    if (!notifDropdown) return;
    dropdownOpen = false;
    notifDropdown.classList.remove('open');
    notifDropdown.setAttribute('aria-hidden', 'true');
}

// Bell button click
if (notifBellBtn) {
    notifBellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdownOpen ? closeDropdown() : openDropdown();
    });
}

// Mark all read button
if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        markAllRead();
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (
        dropdownOpen &&
        notifDropdown &&
        !notifDropdown.contains(e.target) &&
        notifBellBtn &&
        !notifBellBtn.contains(e.target)
    ) {
        closeDropdown();
    }
});

// Prevent dropdown close when clicking inside it
if (notifDropdown) {
    notifDropdown.addEventListener('click', (e) => e.stopPropagation());
}


// ============================================================
// SECTION 8: MOBILE SIDEBAR TOGGLE
// Hamburger button opens/closes sidebar on mobile screens.
// ============================================================
if (sidebarToggleBtn && portalSidebar) {
    sidebarToggleBtn.addEventListener('click', () => {
        portalSidebar.classList.toggle('open');
        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('active');
        }
    });
}

// Close sidebar when overlay is clicked
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
        portalSidebar?.classList.remove('open');
        sidebarOverlay.classList.remove('active');
    });
}


// ============================================================
// SECTION 9: POLLING — CHECK FOR NEW NOTIFICATIONS
// Polls the server every 60 seconds for new notifications.
// Updates the bell count if new ones arrive.
// ============================================================
async function pollNotifications() {
    try {
        const response = await fetch(
            `${window.APP_URL}/api/get-notifications.php?count_only=1`,
            {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }
        );

        if (!response.ok) return;

        const data = await response.json();

        if (data.success && typeof data.unread_count !== 'undefined') {
            const serverCount  = parseInt(data.unread_count) || 0;
            const currentCount = getCurrentBellCount();

            if (serverCount !== currentCount) {
                // Update bell badge to reflect server count
                const existingBadge = notifBellBtn?.querySelector('.topbar-notif-count');

                if (serverCount === 0) {
                    if (existingBadge) existingBadge.remove();
                } else {
                    if (existingBadge) {
                        existingBadge.textContent = serverCount > 99 ? '99+' : serverCount;
                    } else {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'topbar-notif-count';
                        newBadge.textContent = serverCount > 99 ? '99+' : serverCount;
                        notifBellBtn?.appendChild(newBadge);
                    }
                }
            }
        }
    } catch (error) {
        // Silent fail — polling errors should not disrupt the UI
    }
}

// Start polling every 60 seconds
setInterval(pollNotifications, 60000);


// ============================================================
// SECTION 10: UTILITY HELPERS
// ============================================================

/**
 * Escapes HTML special characters to prevent XSS in
 * dynamically rendered notification content.
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


// ============================================================
// SECTION 11: NOTIFICATION DROPDOWN CSS (injected)
// Styles for the dropdown panel, items, and states.
// Injected here because topbar is shared — avoids repeating
// in every role-specific CSS file.
// ============================================================
(function injectNotifStyles() {
    const style = document.createElement('style');
    style.textContent = `

        /* Notification dropdown panel */
        .notif-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 340px;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.14);
            z-index: 9999;
            overflow: hidden;
            animation: notifFadeIn 0.18s ease;
        }

        .notif-dropdown.open {
            display: block;
        }

        @keyframes notifFadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Dropdown header */
        .notif-dropdown-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-bottom: 1px solid #e0e0e0;
            background: #f4f6f9;
        }

        .notif-dropdown-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .notif-mark-all-btn {
            font-size: 11px;
            font-weight: 600;
            color: #006400;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }

        .notif-mark-all-btn:hover {
            color: #004d00;
        }

        /* Dropdown body */
        .notif-dropdown-body {
            max-height: 320px;
            overflow-y: auto;
        }

        /* Loading state */
        .notif-loading {
            padding: 24px 16px;
            text-align: center;
            font-size: 13px;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Empty state */
        .notif-empty-state {
            padding: 36px 16px;
            text-align: center;
            color: #aaa;
        }

        .notif-empty-state i {
            font-size: 28px;
            margin-bottom: 10px;
            display: block;
        }

        .notif-empty-state p {
            font-size: 13px;
            margin: 0;
        }

        /* Individual notification item */
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.15s ease;
            position: relative;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item:hover {
            background: #f4f6f9;
        }

        .notif-item.notif-unread {
            background: #e8f5e9;
        }

        .notif-item.notif-unread:hover {
            background: #d4edda;
        }

        /* Notification icon */
        .notif-item-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        /* Notification text */
        .notif-item-body {
            flex: 1;
            min-width: 0;
        }

        .notif-item-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .notif-item-message {
            font-size: 12px;
            color: #555;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-item-time {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        /* Unread indicator dot */
        .notif-unread-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #006400;
            flex-shrink: 0;
            margin-top: 4px;
        }

        /* Dropdown footer */
        .notif-dropdown-footer {
            padding: 10px 16px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            background: #f4f6f9;
        }

        .notif-view-all-link {
            font-size: 12.5px;
            font-weight: 600;
            color: #006400;
        }

        .notif-view-all-link:hover {
            color: #004d00;
        }

        /* Topbar hamburger button */
        .topbar-hamburger {
            background: #f4f6f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #333;
            font-size: 16px;
            transition: all 0.15s ease;
        }

        .topbar-hamburger:hover {
            background: #e8f5e9;
            border-color: #006400;
            color: #006400;
        }

        /* Topbar user chip */
        .topbar-user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 12px 5px 5px;
            background: #f4f6f9;
            border: 1px solid #e0e0e0;
            border-radius: 50px;
            cursor: default;
        }

        .topbar-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #006400;
            color: #FFD700;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .topbar-user-name {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .topbar-user-role {
            font-size: 11px;
            color: #6c757d;
        }

        /* Sidebar info block (in sidebars) */
        .sidebar-info-block {
            margin: 4px 12px 8px;
            background: rgba(255,255,255,0.06);
            border-radius: 8px;
            padding: 8px 12px;
        }

        .sidebar-info-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-info-row:last-child {
            border-bottom: none;
        }

        .sidebar-info-label {
            font-size: 11px;
            color: rgba(255,255,255,0.55);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .sidebar-info-label i {
            font-size: 10px;
            color: #FFD700;
        }

        .sidebar-info-value {
            font-size: 12px;
            font-weight: 700;
            color: rgba(255,255,255,0.90);
        }

        .cgpa-highlight {
            color: #FFD700 !important;
        }

        /* Sidebar overlay (mobile) */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 999;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Responsive sidebar on mobile */
        @media (max-width: 991.98px) {
            .notif-dropdown {
                width: 300px;
                right: -60px;
            }
        }

        @media (max-width: 575.98px) {
            .notif-dropdown {
                width: 280px;
                right: -80px;
            }
        }
    `;
    document.head.appendChild(style);
})();