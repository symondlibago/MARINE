import js from "@eslint/js";
import react from "eslint-plugin-react";
import hooks from "eslint-plugin-react-hooks";
import globals from "globals";

/**
 * What the build does not check.
 *
 * `vite build` only bundles. It never asks whether a variable you used exists,
 * or whether React's rules are being kept — so a file can bundle cleanly in
 * twelve seconds and hand the browser a white screen. Both of this project's
 * runtime crashes went out through green builds:
 *
 *   ReferenceError: openOrder is not defined     — no-undef
 *   Rendered more hooks than during the previous render
 *                                                — react-hooks/rules-of-hooks
 *
 * Each is one of the oldest rules there is, and each would have failed here in
 * about a second.
 *
 * Two tiers, deliberately:
 *
 *   error  — the code is broken and the browser will show it. The crash class.
 *   warn   — worth tidying, and nobody should be blocked by it today.
 *
 * The whole client is linted rather than only the module being worked on,
 * because a rule that covers one folder is a rule nobody extends later. The
 * severity split is what keeps that honest: old code reports warnings, not a
 * wall of failures, and the errors it does report are real.
 */
export default [
  {
    ignores: [
      "dist/**",
      "node_modules/**",
      "patches/**",
      "**/*.min.js",
      // Vendored shadcn/ui scaffolding. Most of it is unused, and much of it
      // carries TypeScript annotations inside .jsx files, so it does not parse
      // as JavaScript at all. Nobody is going to rewrite forty files of
      // boilerplate to satisfy a linter, and nothing is learned by trying.
      "client/src/components/ui/**",
    ],
  },

  js.configs.recommended,

  {
    files: ["client/src/**/*.{js,jsx}"],

    languageOptions: {
      ecmaVersion: 2023,
      sourceType: "module",
      globals: {
        ...globals.browser,
        ...globals.es2021,
      },
      parserOptions: {
        ecmaFeatures: { jsx: true },
      },
    },

    settings: {
      react: { version: "detect" },
    },

    plugins: {
      react,
      "react-hooks": hooks,
    },

    rules: {
      ...react.configs.flat.recommended.rules,
      ...hooks.configs.recommended.rules,

      /* ---- the crash class: these stop a release ---- */

      // The one that put `openOrder is not defined` in front of the user.
      "no-undef": "error",
      // The one that put "Rendered more hooks…" in front of the user. A hook
      // below an early return runs on some renders and not others, and React
      // cannot match them up.
      "react-hooks/rules-of-hooks": "error",

      /* ---- React 18 with the automatic JSX transform ---- */

      // Nothing in this project imports React to use JSX, and nothing needs to.
      "react/react-in-jsx-scope": "off",
      "react/jsx-uses-react": "off",
      // No prop types anywhere, and adding them to 40 screens is not a
      // morning's work that buys anything.
      "react/prop-types": "off",
      // Deliberate on several screens: a dangerouslySetInnerHTML is reviewed
      // where it is written, not re-argued on every lint run.
      "react/no-unescaped-entities": "off",

      /* ---- worth knowing, not worth blocking ---- */

      // Missing dependencies are usually a real bug and occasionally the whole
      // point, which is why a few are suppressed by name at the call site.
      "react-hooks/exhaustive-deps": "warn",
      // An unused import is clutter; an unused catch binding is a style. Args
      // are left alone because a handler's signature is not noise.
      "no-unused-vars": [
        "warn",
        { args: "none", caughtErrors: "none", ignoreRestSiblings: true },
      ],
      "no-empty": ["warn", { allowEmptyCatch: true }],
    },
  },

  {
    // Config and build files run in Node, not the browser.
    files: ["*.config.js", "vite.config.js"],
    languageOptions: {
      globals: { ...globals.node },
    },
  },
];
