/**
 * Display Scheduler UI Module
 * Handles display scheduling interface for admin panel
 * 
 * Features:
 * - Weekly calendar view
 * - Time slot management
 * - Special day overrides
 * - Status display (ACTIVE / TURNED_OFF)
 */

const GroupLoopDisplayScheduler = (() => {
    'use strict';

    // Constants
    const DAYS_OF_WEEK = ['Vasárnap', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat'];
    const HOUR_RANGE = Array.from({ length: 24 }, (_, i) => i);

    /**
     * Render weekly schedule grid
     */
    const renderScheduleGrid = (schedule) => {
        if (!schedule) return '<p>Nincs ütemezés beállítva</p>';

        const container = document.createElement('div');
        container.className = 'schedule-grid-container';
        container.style.cssText = `
            background: white;
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
        `;

        // Build grid
        const grid = document.createElement('div');
        grid.style.cssText = `
            display: grid;
            grid-template-columns: 80px repeat(7, 1fr);
            gap: 1px;
            background: #ddd;
            padding: 1px;
        `;

        // Header row with day names
        const headerCell = document.createElement('div');
        headerCell.textContent = 'Óra';
        headerCell.style.cssText = `
            background: #f0f0f0;
            padding: 8px;
            font-weight: bold;
            text-align: center;
            font-size: 12px;
        `;
        grid.appendChild(headerCell);

        DAYS_OF_WEEK.forEach(day => {
            const dayCell = document.createElement('div');
            dayCell.textContent = day;
            dayCell.style.cssText = `
                background: #2c3e50;
                color: white;
                padding: 8px;
                font-weight: bold;
                text-align: center;
                font-size: 12px;
            `;
            grid.appendChild(dayCell);
        });

        // Time slots
        for (let hour = 0; hour < 24; hour++) {
            const timeLabel = document.createElement('div');
            timeLabel.textContent = `${String(hour).padStart(2, '0')}:00`;
            timeLabel.style.cssText = `
                background: #f0f0f0;
                padding: 8px;
                font-weight: bold;
                text-align: center;
                font-size: 11px;
            `;
            grid.appendChild(timeLabel);

            for (let day = 0; day < 7; day++) {
                const cell = document.createElement('div');
                const isActive = getHourStatus(schedule, day, hour);
                cell.style.cssText = `
                    background: ${isActive ? '#2ecc71' : '#e74c3c'};
                    padding: 8px;
                    cursor: pointer;
                    transition: opacity 0.2s;
                    text-align: center;
                    font-size: 11px;
                    color: white;
                    user-select: none;
                `;
                cell.textContent = isActive ? '✓' : '✕';
                cell.title = `${DAYS_OF_WEEK[day]} ${String(hour).padStart(2, '0')}:00`;

                cell.addEventListener('click', () => {
                    toggleHourStatus(schedule, day, hour);
                });

                cell.addEventListener('mouseover', () => {
                    cell.style.opacity = '0.7';
                });

                cell.addEventListener('mouseout', () => {
                    cell.style.opacity = '1';
                });

                grid.appendChild(cell);
            }
        }

        container.appendChild(grid);

        // Legend
        const legend = document.createElement('div');
        legend.style.cssText = `
            margin-top: 15px;
            font-size: 12px;
            color: #555;
        `;
        legend.innerHTML = `
            <div><span style="background: #2ecc71; color: white; padding: 2px 6px; border-radius: 3px;">✓ Aktív</span> - Kijelző bekapcsolt</div>
            <div style="margin-top: 5px;"><span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 3px;">✕ Ki</span> - Kijelző kikapcsolt</div>
        `;
        container.appendChild(legend);

        return container;
    };

    /**
     * Get hour status from schedule
     */
    const getHourStatus = (schedule, day, hour) => {
        if (!schedule || !schedule.time_slots) return true; // Default to active

        const timeStr = String(hour).padStart(2, '0') + ':00:00';

        for (const slot of schedule.time_slots) {
            if (slot.day_of_week === day) {
                const startMinutes = timeToMinutes(slot.start_time);
                const endMinutes = timeToMinutes(slot.end_time);
                const currentMinutes = hour * 60;

                // Handle overnight ranges (e.g., 22:00 - 06:00)
                if (startMinutes > endMinutes) {
                    if (currentMinutes >= startMinutes || currentMinutes < endMinutes) {
                        return slot.is_enabled;
                    }
                } else {
                    if (currentMinutes >= startMinutes && currentMinutes < endMinutes) {
                        return slot.is_enabled;
                    }
                }
            }
        }

        return true; // Default to active if no matching slot
    };

    /**
     * Toggle hour status
     */
    const toggleHourStatus = (schedule, day, hour) => {
        // TODO: Implement time slot update via API
        alert(`Hétfő ${String(hour).padStart(2, '0')}:00 statuusza módosítva`);
    };

    /**
     * Convert time string to minutes
     */
    const timeToMinutes = (timeStr) => {
        const [h, m] = timeStr.split(':').map(Number);
        return (h * 60) + m;
    };

    /**
     * Render status indicator
     */
    const renderStatusIndicator = (status) => {
        const indicator = document.createElement('div');
        indicator.style.cssText = `
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
        `;

        if (status === 'ACTIVE') {
            indicator.style.background = '#2ecc71';
            indicator.style.color = 'white';
            indicator.textContent = '● Aktív';
        } else if (status === 'TURNED_OFF') {
            indicator.style.background = '#e74c3c';
            indicator.style.color = 'white';
            indicator.textContent = '● Kikapcsolt';
        } else {
            indicator.style.background = '#95a5a6';
            indicator.style.color = 'white';
            indicator.textContent = '● ' + status;
        }

        return indicator;
    };

    /**
     * Get current display status from API
     */
    const getDisplayStatus = async (kijelzo_id) => {
        try {
            const response = await fetch(`/api/kijelzo/${kijelzo_id}/schedule_status`);
            const data = await response.json();
            return data.status || 'UNKNOWN';
        } catch (error) {
            console.error('Error fetching display status:', error);
            return 'ERROR';
        }
    };

    /**
     * Create scheduling panel
     */
    const createSchedulingPanel = (kijelzo_id, schedule) => {
        const panel = document.createElement('div');
        panel.className = 'display-scheduling-panel';
        panel.style.cssText = `
            background: #f5f7fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        `;

        // Title
        const title = document.createElement('h3');
        title.textContent = '📅 Kijelzo Ütemezés';
        title.style.cssText = 'margin-top: 0; color: #2c3e50;';
        panel.appendChild(title);

        // Status section
        const statusSection = document.createElement('div');
        statusSection.style.cssText = `
            background: white;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        `;

        const statusLabel = document.createElement('p');
        statusLabel.textContent = 'Aktuális státusz:';
        statusLabel.style.cssText = 'margin: 0 0 10px 0; font-weight: bold; color: #555;';
        statusSection.appendChild(statusLabel);

        // Load status asynchronously
        getDisplayStatus(kijelzo_id).then(status => {
            statusSection.appendChild(renderStatusIndicator(status));
        });

        panel.appendChild(statusSection);

        // Schedule grid
        if (schedule) {
            panel.appendChild(renderScheduleGrid(schedule));
        } else {
            const noSchedule = document.createElement('p');
            noSchedule.textContent = 'Nincs ütemezés beállítva. Az ütemezés az admin felületen állítható be.';
            noSchedule.style.cssText = 'color: #777; font-style: italic;';
            panel.appendChild(noSchedule);
        }

        // Info box
        const infbox = document.createElement('div');
        infbox.style.cssText = `
            background: #ecf0f1;
            padding: 12px;
            border-left: 4px solid #3498db;
            margin-top: 15px;
            border-radius: 4px;
            font-size: 13px;
            color: #555;
        `;
        infbox.innerHTML = `
            <strong>ℹ️ Információ:</strong><br>
            <ul style="margin: 5px 0; padding-left: 20px;">
                <li><strong>Zöld</strong> (✓): Kijelző bekapcsolt, tartalom megjelenik</li>
                <li><strong>Piros</strong> (✕): Kijelző kikapcsolt, szolgáltatás szünetel</li>
                <li>Alapértelmezés: 22:00 - 06:00 között kikapcsolt</li>
            </ul>
        `;
        panel.appendChild(infbox);

        return panel;
    };

    /**
     * Export public API
     */
    return {
        renderScheduleGrid,
        renderStatusIndicator,
        getDisplayStatus,
        createSchedulingPanel,
        getHourStatus,
        timeToMinutes
    };
})();
