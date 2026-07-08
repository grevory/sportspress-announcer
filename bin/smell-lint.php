<?php
/**
 * Mechanical code-smell linter — the enforceable subset of the code-smells
 * skill catalog. Runs in pre-commit. Semantic smells (Feature Envy, etc.)
 * are for the /code-smells skill; this only catches the countable ones.
 *
 * Usage:
 *   php bin/smell-lint.php [file ...]    # lint given files (default: staged)
 *
 * Exit 0 = no high-severity smells; 1 = high-severity smell found.
 */

const MAX_METHOD_LINES = 60;
const MAX_PARAMS       = 5;
const MAX_NESTING      = 4;

function staged_php_files(): array {
	exec("git diff --cached --name-only --diff-filter=ACM -- '*.php'", $out);
	return array_values(array_filter($out, fn($f) => is_file($f)
		&& !preg_match('#(^|/)(vendor|tests)/#', $f)));
}

$files = array_slice($argv, 1);
if (!$files) {
	$files = staged_php_files();
}

$high = [];
$warn = [];

foreach ($files as $file) {
	$src   = file_get_contents($file);
	$lines = explode("\n", $src);

	// --- Long Method + Long Parameter List (function bodies) ---
	foreach ($lines as $i => $line) {
		if (preg_match('/function\s+\w+\s*\(([^)]*)\)/', $line, $m)) {
			$ln     = $i + 1;
			$params = trim($m[1]) === '' ? [] : explode(',', $m[1]);
			if (count($params) > MAX_PARAMS) {
				$high[] = "$file:$ln Long Parameter List (" . count($params)
					. " params) — Introduce Parameter Object";
			}
			// measure body length by brace matching from this line
			$len = method_body_lines($lines, $i);
			if ($len > MAX_METHOD_LINES) {
				$high[] = "$file:$ln Long Method ($len lines) — Extract Method";
			}
		}
	}

	// --- Deep nesting (indentation depth of control keywords) ---
	// Only measure lines that are actual PHP. Use the tokenizer to find which
	// lines contain PHP control-flow keywords (T_IF/T_FOREACH/…) so that "if"
	// inside inline <script> or HTML template output is never miscounted.
	$php_control_lines = php_control_flow_lines($src);
	foreach ($lines as $i => $line) {
		if (isset($php_control_lines[ $i + 1 ])
			&& preg_match('/^(\t+)/', $line, $m)) {
			$depth = strlen($m[1]);
			if ($depth > MAX_NESTING) {
				$high[] = ($file . ':' . ($i + 1))
					. " Deep Nesting (depth $depth) — use guard clauses";
			}
		}
	}

	// --- Duplicated Code (repeated non-trivial lines within a file) ---
	$seen = [];
	foreach ($lines as $i => $line) {
		$t = trim($line);
		if (strlen($t) > 40 && !preg_match('#^(//|\*|/\*)#', $t)) {
			$seen[$t][] = $i + 1;
		}
	}
	foreach ($seen as $t => $locs) {
		if (count($locs) >= 3) {
			$warn[] = "$file:{$locs[0]} Duplicated Code (x" . count($locs)
				. ") — Extract Method";
		}
	}
}

foreach ($warn as $w) { fwrite(STDERR, "warn: $w\n"); }

if ($high) {
	foreach ($high as $h) { fwrite(STDERR, "SMELL $h\n"); }
	fwrite(STDERR, "\n" . count($high) . " high-severity smell(s) found.\n");
	exit(1);
}

fwrite(STDERR, "no high-severity smells\n");
exit(0);

/**
 * Line numbers on which a real PHP control-flow keyword appears, keyed by line.
 *
 * @return array<int,true>
 */
function php_control_flow_lines(string $src): array {
	$targets = [T_IF, T_FOREACH, T_FOR, T_WHILE, T_SWITCH];
	$lines   = [];
	foreach (token_get_all($src) as $token) {
		if (is_array($token) && in_array($token[0], $targets, true)) {
			$lines[ $token[2] ] = true;
		}
	}
	return $lines;
}

/** Count lines of a method body starting at the line index of its signature. */
function method_body_lines(array $lines, int $start): int {
	$depth = 0; $started = false; $count = 0;
	for ($i = $start, $n = count($lines); $i < $n; $i++) {
		$open  = substr_count($lines[$i], '{');
		$close = substr_count($lines[$i], '}');
		if (!$started && $open > 0) { $started = true; }
		$depth += $open - $close;
		if ($started) { $count++; }
		if ($started && $depth <= 0) { break; }
	}
	return $count;
}
