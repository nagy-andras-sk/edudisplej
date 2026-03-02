(function () {
    function normalizeMealLine(line) {
        let value = String(line || '').trim();
        value = value.replace(/^\d+\.\s*/u, '');
        value = value.replace(/^\d{1,2}\.\d{3}(?:\s*[\)\].,;:-])?\s*/u, '');
        value = value.replace(/\b\d{1,2}\.\d{3}\b(?:\s*[,;:.\)\(\-])?/gu, '');
        value = value.replace(/,\s*(?:Kysličník|Obilniny|Vajcia|Mlieko|Ryby|Zeler|Horčica|Orech|Sezam|S[oó]j|Lupina|M[aä]kk[ýy]še).*$/iu, '');
        value = value.replace(/\s+\d{1,2}\.\d{3}\s*$/u, '');
        value = value.replace(/,\s*,+/g, ', ');
        value = value.replace(/\s+,/g, ',');
        value = value.replace(/,\s*$/g, '');
        value = value.replace(/\s+\)\s*$/u, '');
        value = value.replace(/[ \t]{2,}/g, ' ').trim();
        return value;
    }

    function normalizeMealAlias(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z]/g, '');
    }

    function mealAliasToKey(value) {
        const n = normalizeMealAlias(value);
        if (n === 'breakfast' || n === 'ranajky' || n === 'reggeli') return 'breakfast';
        if (n === 'snackam' || n === 'desiata' || n === 'tizorai') return 'snack_am';
        if (n === 'lunch' || n === 'obed' || n === 'ebed') return 'lunch';
        if (n === 'snackpm' || n === 'olovrant' || n === 'uzsonna') return 'snack_pm';
        if (n === 'dinner' || n === 'vecera' || n === 'vacsora') return 'dinner';
        return '';
    }

    function stripSourceNoise(text) {
        return String(text || '')
            .replace(/\bbody=\[[\s\S]*$/giu, '')
            .replace(/\b(header|windowlock|cssbody|cssheader|doubleclickstop|singleclickstop|requireclick|hideselects|fade)=\[[^\]]*\]/giu, '')
            .replace(/\be\.hod\.[^\n\r]*$/giu, '')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function stripAllergenAnnotations(text) {
        return String(text || '')
            .replace(/\((?:allerg[eé]n(?:ek)?|alerg[eé]ny(?:ek)?)\s*:\s*[^)]*\)/giu, '')
            .replace(/(?:allerg[eé]n(?:ek)?|alerg[eé]ny(?:ek)?)\s*:\s*[^\n\r;]+/giu, '')
            .replace(/ALERG[ÉE]NY:\s*[^\n\r]+/giu, '')
            .replace(/\([^)]*ALERG[ÉE]NY:[^)]*\)/giu, '')
            .replace(/\([^)]*allerg[eé]n[^)]*\)/giu, '')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function extractAllergenLabels(text) {
        const raw = String(text || '');
        const labels = [];
        const matches = raw.match(/ALERG[ÉE]NY:\s*([^\n\r)]+)/giu) || [];
        matches.forEach((m) => {
            const part = String(m).replace(/ALERG[ÉE]NY:\s*/iu, '').trim();
            part.split(',').map((item) => item.trim()).filter(Boolean).forEach((item) => labels.push(item));
        });
        return Array.from(new Set(labels));
    }

    function extractAllergenCodes(text) {
        const raw = String(text || '');
        const codeSet = new Set();
        const patterns = [
            /\((?:allerg[eé]n(?:ek)?|alerg[eé]ny(?:ek)?)\s*:\s*([^)]+)\)/giu,
            /(?:allerg[eé]n(?:ek)?|alerg[eé]ny(?:ek)?)\s*:\s*([^\n\r;]+)/giu
        ];

        patterns.forEach((pattern) => {
            let match;
            while ((match = pattern.exec(raw)) !== null) {
                const part = String(match[1] || '');
                const nums = part.match(/\b([1-9]|1[0-4])\b/g) || [];
                nums.forEach((numStr) => {
                    const code = parseInt(numStr, 10);
                    if (code >= 1 && code <= 14) {
                        codeSet.add(code);
                    }
                });
            }
        });

        return Array.from(codeSet).sort((a, b) => a - b);
    }

    function isLikelyDrinkLine(rawLine, normalizedLine) {
        const source = `${String(rawLine || '').toLowerCase()} ${String(normalizedLine || '').toLowerCase()}`;
        if (source.includes('čaj') || source.includes('caj') || source.includes('tea')) return true;
        if (source.includes('mlieko') || source.includes('kakao') || source.includes('káv') || source.includes('kava') || source.includes('coffee')) return true;
        if (source.includes('nápoj') || source.includes('napoj') || source.includes('džús') || source.includes('dzus') || source.includes('juice')) return true;
        if (source.includes('voda') || source.includes('water') || source.includes('sirup')) return true;
        return false;
    }

    function normalizeDedupKey(line) {
        return String(line || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    }

    function parseMealRowsForSmall(rawMealText) {
        const rawText = stripAllergenAnnotations(stripSourceNoise(String(rawMealText || '').trim()));
        const rawLines = String(rawText || '')
            .split(/\r?\n/)
            .map((line) => String(line || '').trim())
            .filter(Boolean);

        const seen = new Set();
        const rows = [];
        rawLines.forEach((rawLine) => {
            const normalized = normalizeMealLine(rawLine);
            if (!normalized) {
                return;
            }

            const aliasKey = mealAliasToKey(normalized);
            if (aliasKey && normalized.length <= 20) {
                return;
            }

            const dedupKey = normalizeDedupKey(normalized);
            if (!dedupKey || seen.has(dedupKey)) {
                return;
            }
            seen.add(dedupKey);

            rows.push({
                text: normalized,
                isDrink: isLikelyDrinkLine(rawLine, normalized),
                icon: ''
            });
        });

        if (!rows.length) {
            return [{ text: '—', isDrink: false, icon: 'cutlery' }];
        }

        const drinkIndex = rows.findIndex((row) => row.isDrink === true);
        if (drinkIndex >= 0) {
            rows[drinkIndex].icon = 'drink';
        }

        const mainMealIndex = rows.findIndex((row) => row.isDrink !== true);
        if (mainMealIndex >= 0) {
            rows[mainMealIndex].icon = 'cutlery';
        } else {
            rows[0].icon = 'cutlery';
        }

        return rows;
    }

    window.MealMenuUtils = {
        normalizeMealLine,
        normalizeMealAlias,
        mealAliasToKey,
        stripSourceNoise,
        stripAllergenAnnotations,
        extractAllergenLabels,
        extractAllergenCodes,
        parseMealRowsForSmall
    };
})();
