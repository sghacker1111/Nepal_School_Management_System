"""Shared real HTTP/PDO fixture; SQLite temp storage or an explicit disposable MySQL target."""
import json, os, re, socket, sqlite3, subprocess, sys, tempfile, time
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from http.cookiejar import CookieJar
from html.parser import HTMLParser

def visible_exam_text(html):
    """Ignore inactive pre-rendered print languages, retaining visible question text."""
    class ExamText(HTMLParser):
        def __init__(self):super().__init__(convert_charrefs=True);self.stack=[];self.text=[]
        def handle_starttag(self,tag,attrs):
            attrs=dict(attrs);skip=(self.stack[-1][1] if self.stack else False) or ('data-exam-language-view' in attrs and 'hidden' in attrs)
            if tag not in ('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'):self.stack.append((tag,skip))
        def handle_endtag(self,tag):
            for i in range(len(self.stack)-1,-1,-1):
                if self.stack[i][0]==tag:del self.stack[i:];break
        def handle_data(self,data):
            if not self.stack or not self.stack[-1][1]:self.text.append(data)
    parser=ExamText();parser.feed(html);return ''.join(parser.text)

class ERPHttpFixture:
    def __init__(self,session_name=None):
        if session_name is not None and not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,60}',session_name):raise ValueError('Invalid test session name')
        self.session_name=session_name
    def __enter__(self):
        self.native_mysql=None
        try:return self._start()
        except BaseException:
            if hasattr(self,'server'):self.server.terminate();self.server.wait(timeout=10)
            if hasattr(self,'db'):self.db.close()
            if self.native_mysql:self.native_mysql.__exit__(*sys.exc_info())
            raise
    def _start(self):
        self.root=Path(__file__).resolve().parents[1]
        self.tmp=tempfile.TemporaryDirectory(prefix='erp-http-fixture-')
        native_http=os.environ.get('ERP_HTTP_NATIVE_MYSQL')=='1'
        self.mysql=native_http or os.environ.get('ERP_TEST_MYSQL')=='1'
        if self.mysql:
            if native_http:
                from mysql_test_support import MysqlImportFixture
                self.native_mysql=MysqlImportFixture(clean_accounting=True)
                self.native_mysql.__enter__();self.native_mysql.import_and_verify()
                self.env={**self.native_mysql.env,'ERP_RUNTIME':'test','ERP_TEST_MYSQL':'1'}
            else:
                if os.environ.get('ERP_DB_HOST')!='127.0.0.1' or not os.environ.get('ERP_DB_NAME','').startswith('erp_migration_test_'):raise RuntimeError('HTTP MySQL fixtures require a disposable loopback database.')
                self.env={**os.environ,'ERP_RUNTIME':'test','ERP_DB_TYPE':'mysql'}
            for key in ('ERP_TEST_SQLITE','ERP_DB_PATH'):self.env.pop(key,None)
        else:self.env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(Path(self.tmp.name)/'test.sqlite')}
        self.php=os.environ.get('ERP_TEST_PHP','php')
        self.fixture=json.loads(subprocess.run([self.php,'tests/http_fixture.php'],cwd=self.root,env=self.env,capture_output=True,text=True,check=True).stdout)
        if self.mysql:
            from mysql_http_support import MySQLFixtureConnection
            self.db=MySQLFixtureConnection(self.php,self.root,self.env)
        else:self.db=sqlite3.connect(self.env['ERP_DB_PATH'])
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        self.base=f'http://127.0.0.1:{port}'
        self.log=open(Path(self.tmp.name)/'server.log','w+',encoding='utf-8')
        self.session_dir=Path(self.tmp.name)/'sessions';self.session_dir.mkdir()
        server_command=[self.php,'-d','session.save_path='+str(self.session_dir)]+(['-d','session.name='+self.session_name] if self.session_name else [])+['-S',f'127.0.0.1:{port}','router.php']
        self.server=subprocess.Popen(server_command,cwd=self.root,env=self.env,stdout=self.log,stderr=self.log)
        self.opener=build_opener(HTTPCookieProcessor(CookieJar()));self.token='';self.count=0
        for attempt in range(100):
            try:
                self.opener.open(self.base+'/modules/auth/login.php',timeout=1).close();break
            except (URLError,ConnectionError):
                if self.server.poll() is not None:raise RuntimeError('Test server stopped')
                time.sleep(.05)
        else:raise RuntimeError('Test server did not start')
        return self
    def __exit__(self,kind,value,trace):
        self.db.close();self.server.terminate();self.server.wait(timeout=10)
        self.log.flush();self.log.seek(0);logs=self.log.read();self.log.close();self.tmp.cleanup()
        if self.native_mysql:self.native_mysql.__exit__(kind,value,trace)
        if not kind:
            issues=[line for line in logs.splitlines() if re.search(r'PHP (Warning|Deprecated|Fatal|Parse)',line)]
            assert not issues,'\n'.join(issues)
    def expire_session(self):
        handler=next(handler for handler in self.opener.handlers if isinstance(handler,HTTPCookieProcessor))
        cookie=next(cookie for cookie in handler.cookiejar if cookie.name==(self.session_name or 'PHPSESSID'))
        assert re.fullmatch(r'[A-Za-z0-9,-]{1,128}',cookie.value)
        path=self.session_dir/('sess_'+cookie.value)
        original=path.read_bytes();assert b'user_id|' in original
        expired,count=re.subn(rb'login_time\|i:[0-9]+;',b'login_time|i:'+str(int(time.time())-3601).encode()+b';',original)
        assert count==1,'Expected one authenticated fixture session timestamp'
        path.write_bytes(expired)
    def request_bytes(self,path,data=None,status=200,csrf=True):
        if data is not None:
            data=dict(data)
            if csrf:data['csrf_token']=self.token
        request=Request(self.base+path,data=urlencode(data,doseq=True).encode() if data is not None else None)
        try:response=self.opener.open(request,timeout=20)
        except HTTPError as error:response=error
        with response:
            body=response.read();headers=response.headers;actual=response.code
        assert actual==status,(path,actual,body[:1000])
        if 'text/' in headers.get('Content-Type','') or body.startswith(b'<!') or body.startswith(b'<'):
            assert not re.search(rb'(?:Fatal error|Warning|Deprecated|Parse error)(?:</b>)?:[^\n]*(?: on line | in [A-Z]:| in /)|Uncaught (?:Error|Exception)',body),(path,body[-2000:])
        self.count+=1
        return headers,body
    def request(self,path,data=None,status=200,csrf=True):
        return self.request_bytes(path,data,status,csrf)[1].decode('utf-8')
    def login(self,username):
        self.opener=build_opener(HTTPCookieProcessor(CookieJar()))
        body=self.request('/modules/auth/login.php')
        self.token=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1)
        body=self.request('/modules/auth/login.php',{'username':username,'password':'TestPassword123!'})
        self.token=re.search(r'name="csrf-token" content="([^"]+)"',body).group(1)
        return body
    def snapshot(self,table):
        assert re.fullmatch(r'[a-z_]+',table)
        if self.mysql:
            fields=self.db.execute('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',(table,)).fetchall()
            assert fields,'Unknown table'
            keys=self.db.execute('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=\'PRIMARY\' ORDER BY ORDINAL_POSITION',(table,)).fetchall()
            order=','.join('`'+row[0]+'`' for row in keys) if keys else '1'
            return self.db.execute('SELECT * FROM `'+table+'` ORDER BY '+order).fetchall()
        fields=self.db.execute(f'PRAGMA table_info({table})').fetchall()
        assert fields,'Unknown table'
        keys=[row[1] for row in sorted(fields,key=lambda row:row[5]) if row[5]]
        order=','.join('"'+key.replace('"','""')+'"' for key in keys) if keys else '1'
        return self.db.execute(f'SELECT * FROM {table} ORDER BY {order}').fetchall()
