/**
 * Group Loop Editor - Module Index
 * 
 * BETÖLTÉSI SORREND (critical):
 * 1. modules/utils.js       (alapvető segédfüggvények)
 * 2. modules/text-editor.js (szövegszerkesztő, utils-on alapul)
 * 3. app.js                 (fő alkalmazás)
 * 
 * HTML-ben:
 * <script src="modules/utils.js"></script>
 * <script src="modules/text-editor.js"></script>
 * <script src="app.js"></script>
 */

/* Modulok elérhetősége globálisan:
 * - GroupLoopUtils       - Segédfüggvények
 * - GroupLoopTextEditor  - Szövegszerkesztő
 * - App-specifikus state a window.GroupLoopBootstrap-ből
 */

console.log('✓ GroupLoopUtils loaded:', typeof GroupLoopUtils !== 'undefined');
console.log('✓ GroupLoopTextEditor loaded:', typeof GroupLoopTextEditor !== 'undefined');
console.log('✓ Group Loop Editor Ready');
