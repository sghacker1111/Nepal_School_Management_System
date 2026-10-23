"""Native MySQL fixture queries; never used by application requests."""
import json
import re
import subprocess
from decimal import Decimal


class MySQLFixtureCursor:
    def __init__(self, result):
        types=result.get('types',[])
        self.rows = [tuple(Decimal(value) if value is not None and index<len(types) and types[index] in ('DECIMAL','NEWDECIMAL') else value for index,value in enumerate(row)) for row in result.get('rows', [])]
        self.lastrowid = result.get('lastrowid', 0)
        self.rowcount = result.get('rowcount', 0)

    def fetchone(self):
        return self.rows.pop(0) if self.rows else None

    def fetchall(self):
        result, self.rows = self.rows, []
        return result

    def __iter__(self):
        return iter(self.rows)


class MySQLFixtureConnection:
    def __init__(self, php, root, env):
        self.process = subprocess.Popen([php, 'tests/mysql_query_bridge.php'], cwd=root, env=env,
                                        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                        text=True, encoding='utf-8')

    def _send(self, message):
        self.process.stdin.write(json.dumps(message, ensure_ascii=False) + '\n')
        self.process.stdin.flush()
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Native MySQL fixture bridge stopped unexpectedly.')
        result = json.loads(line)
        if not result.get('ok'):
            raise RuntimeError('Native MySQL fixture query failed: ' + result['error'] + ' ' + result['code'])
        return result['result']

    def execute(self, sql, parameters=()):
        # Only test-setup identity retrieval is translated; application SQL stays native.
        sql = re.sub(r'\blast_insert_rowid\(\)', 'LAST_INSERT_ID()', sql, flags=re.I)
        return MySQLFixtureCursor(self._send({'sql': sql, 'parameters': list(parameters)}))

    def commit(self):
        self._send({'action': 'commit'})

    def rollback(self):
        self._send({'action': 'rollback'})

    def close(self):
        if self.process.poll() is None:
            self.process.stdin.write('{"action":"close"}\n')
            self.process.stdin.flush()
            self.process.wait(timeout=10)
        self.process.stdin.close()
        self.process.stdout.close()
        self.process.stderr.close()
