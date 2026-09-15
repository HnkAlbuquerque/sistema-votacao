/**
 * @file
 * Build pipeline: design tokens (JSON) -> SCSS -> CSS.
 *
 *   npx gulp build   compile once
 *   npx gulp watch   rebuild on change
 *   npx gulp tokens  regenerate scss/abstracts/_tokens.generated.scss only
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { src, dest, series, watch } = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const postcss = require('gulp-postcss');
const autoprefixer = require('autoprefixer');

const paths = {
  tokens: 'tokens/design-tokens.json',
  generated: 'scss/abstracts/_tokens.generated.scss',
  scss: 'scss/**/*.scss',
  entry: 'scss/main.scss',
  out: 'css',
};

/**
 * Flattens a nested token group into "a-b-c: value" pairs.
 */
function flatten(group, prefix = []) {
  return Object.entries(group).flatMap(([key, value]) =>
    typeof value === 'object' && value !== null
      ? flatten(value, [...prefix, key])
      : [[[...prefix, key].join('-'), value]]
  );
}

/**
 * Turns the JSON tokens into CSS custom properties and SCSS variables.
 */
function tokens(cb) {
  const t = JSON.parse(fs.readFileSync(paths.tokens, 'utf8'));
  const lines = [
    '// Generated from tokens/design-tokens.json by `gulp tokens`. Do not edit.',
    '',
  ];

  // Breakpoints must be SCSS values: media queries cannot read custom properties.
  const breakpoints = Object.entries(t.breakpoint)
    .map(([k, v]) => `${k}: ${v}`)
    .join(', ');
  lines.push(`$breakpoints: (${breakpoints});`, '');

  const light = [];
  const dark = [];
  for (const [name, value] of Object.entries(t.color)) {
    light.push(`  --color-${name}: ${value.light};`);
    dark.push(`  --color-${name}: ${value.dark};`);
  }
  for (const group of ['radius', 'space', 'font', 'shadow', 'motion', 'layout']) {
    for (const [name, value] of flatten(t[group])) {
      light.push(`  --${group}-${name}: ${value};`);
    }
  }

  lines.push(':root {', ...light, '}', '');
  lines.push('@media (prefers-color-scheme: dark) {', '  :root {', ...dark.map((l) => '  ' + l), '  }', '}', '');

  fs.mkdirSync(path.dirname(paths.generated), { recursive: true });
  fs.writeFileSync(paths.generated, lines.join('\n'));
  cb();
}

/**
 * Compiles SCSS to expanded CSS with vendor prefixes.
 */
function styles() {
  return src(paths.entry)
    .pipe(sass({ style: 'expanded', loadPaths: ['scss'] }).on('error', sass.logError))
    .pipe(postcss([autoprefixer()]))
    .pipe(dest(paths.out));
}

const build = series(tokens, styles);

function watcher() {
  watch([paths.scss, paths.tokens], { ignored: paths.generated }, build);
}

exports.tokens = tokens;
exports.build = build;
exports.watch = series(build, watcher);
exports.default = build;
