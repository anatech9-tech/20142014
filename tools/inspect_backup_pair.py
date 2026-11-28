#!/usr/bin/env python3
"""Read-only byte-level check of two separate backup files; never parses or executes SQL."""
import argparse
import hashlib
import hmac
import json
import os
import re
import stat
import sys

class InspectionError(Exception):
    pass

def inspect_file(path):
    if not hasattr(os, 'O_NOFOLLOW'):
        raise InspectionError('Platform cannot refuse symbolic links')
    flags = os.O_RDONLY | os.O_NOFOLLOW | getattr(os, 'O_NONBLOCK', 0)
    try:
        fd = os.open(path, flags)
    except (OSError, ValueError):
        raise InspectionError('Cannot open a backup as a regular file') from None
    try:
        first = os.fstat(fd)
        if not stat.S_ISREG(first.st_mode) or first.st_size <= 0:
            raise InspectionError('Backup must be a nonempty regular file')
        digest = hashlib.sha256()
        length = 0
        while length < first.st_size:
            block = os.read(fd, min(1024 * 1024, first.st_size - length))
            if not block:
                break
            length += len(block)
            digest.update(block)
        last = os.fstat(fd)
        identity = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        if identity(first) != identity(last) or length != first.st_size:
            raise InspectionError('Backup changed while being read')
        return {'size_bytes': length, 'sha256': digest.hexdigest()}, (first.st_dev, first.st_ino)
    except OSError:
        raise InspectionError('Cannot read a backup') from None
    finally:
        os.close(fd)

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site-db', required=True, help='site/shop backup file')
    parser.add_argument('--crm-db', required=True, help='CRM backup file')
    parser.add_argument('--expect-site-sha256')
    parser.add_argument('--expect-crm-sha256')
    args = parser.parse_args(argv)
    expected = (args.expect_site_sha256, args.expect_crm_sha256)
    supplied = tuple(x is not None for x in expected)
    if any(supplied) and not all(supplied):
        parser.error('both expected hashes must be supplied together')
    if all(supplied) and not all(re.fullmatch(r'[a-f0-9]{64}', x) for x in expected):
        parser.error('expected hashes must be lowercase SHA-256 hex')
    try:
        site, site_identity = inspect_file(args.site_db)
        crm, crm_identity = inspect_file(args.crm_db)
        if site_identity == crm_identity:
            raise InspectionError('Backups must be different files, not the same file or a hard link')
        if all(supplied) and (not hmac.compare_digest(site['sha256'], expected[0]) or not hmac.compare_digest(crm['sha256'], expected[1])):
            raise InspectionError('Expected checksum mismatch')
    except InspectionError as exc:
        print('Inspection failed: ' + str(exc), file=sys.stderr)
        return 1
    print(json.dumps({'site': site, 'crm': crm, 'matches_expected': True if all(supplied) else None,
                      'identical_bytes': hmac.compare_digest(site['sha256'], crm['sha256']),
                      'notice': 'Byte checks only; no SQL parsing, authenticity, completeness or restore verification.'}, sort_keys=True))
    return 0

if __name__ == '__main__':
    sys.exit(main())
