#!/usr/bin/env python3
"""Post-write guard with SQLite triggers, not MySQL or real concurrency."""
import os
import pathlib
import subprocess

root = pathlib.Path(__file__).resolve().parents[2]
result = subprocess.run([os.environ.get('PHP_BIN', 'php'), str(root/'crm_derakht_sepid/tests/site_content_postcondition_fixture.php')], cwd=root, text=True, capture_output=True, check=True)
output = result.stdout + result.stderr
assert not any(x in output for x in ('FAIL ', 'Fatal error', 'Warning:', 'Uncaught', 'Parse error')), output
assert all(x in output for x in ('PASS no-op update', 'PASS removed rolls back row and receipt', 'PASS tampered completed_at rolls back', 'PASS expired persists after correction')), output
print(output, end='')
