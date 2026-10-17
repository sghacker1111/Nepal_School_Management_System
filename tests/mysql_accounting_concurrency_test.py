"""Two native PDO processes race the same accounting payment idempotency key."""
import json
import subprocess
import unittest
from mysql_test_support import MysqlImportFixture, ROOT


class MySQLAccountingConcurrencyTests(unittest.TestCase):
    def test_concurrent_payment_replay_posts_once(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env = {**fixture.env, 'ERP_TEST_MYSQL': '1'}
            command = [env['ERP_TEST_PHP'], 'tests/mysql_accounting_concurrency_fixture.php']
            setup = subprocess.run(command+['setup'], cwd=ROOT, env=env, capture_output=True, text=True, check=True)
            payload = json.dumps(json.loads(setup.stdout))
            workers = [subprocess.Popen(command+['pay', payload], cwd=ROOT, env=env, stdin=subprocess.PIPE,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(2)]
            try:
                for worker in workers:
                    self.assertEqual(worker.stdout.readline().strip(), 'READY')
                for worker in workers:
                    worker.stdin.write('GO\n');worker.stdin.flush()
                results = [worker.communicate(timeout=30) for worker in workers]
                for worker, (stdout, stderr) in zip(workers, results):
                    self.assertEqual(worker.returncode, 0, stderr[-1000:])
                self.assertEqual(json.loads(results[0][0]), json.loads(results[1][0]))
            finally:
                for worker in workers:
                    if worker.poll() is None:
                        worker.kill();worker.wait(timeout=10)
            verified = subprocess.run(command+['verify', payload], cwd=ROOT, env=env, capture_output=True, text=True)
            self.assertEqual(verified.returncode, 0, verified.stderr[-1500:])
            report = json.loads(verified.stdout)
            self.assertTrue(report['ok']);self.assertEqual(report['payment_count'], 1)
            self.assertEqual(report['balance_minor'], 0)


if __name__ == '__main__':
    unittest.main()
