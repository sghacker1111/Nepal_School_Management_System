"""Offline migration CLI. School data is written only to a private output folder."""
import argparse,json,sys
from pathlib import Path
from mysql_migration.snapshot import capture_snapshot,source_matches,MigrationError

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source',required=True,type=Path);parser.add_argument('--output',required=True,type=Path)
    parser.add_argument('--snapshot-only',action='store_true');args=parser.parse_args()
    try:
        if not args.snapshot_only:raise MigrationError('Full MySQL export is not available yet; use --snapshot-only for a private preflight snapshot.')
        snapshot=capture_snapshot(args.source,args.output)
        if not source_matches(snapshot):raise MigrationError('Source changed after the snapshot; take a new snapshot during a maintenance window.')
        print(json.dumps({'snapshot':str(snapshot.path),'tables':len(snapshot.manifest['tables']),'rows':sum(t['row_count'] for t in snapshot.manifest['tables'].values()),'ready':False}))
    except (MigrationError,FileNotFoundError) as error:print('Migration stopped: '+str(error),file=sys.stderr);return 1
    return 0

if __name__=='__main__':raise SystemExit(main())
