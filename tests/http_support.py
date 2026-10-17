"""Shared real HTTP/PDO fixture; all mutations target an OS-temp SQLite database."""
import json, os, re, socket, sqlite3, subprocess, tempfile, time
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
        self.root=Path(__file__).resolve().parents[1]
        self.tmp=tempfile.TemporaryDirectory(prefix='erp-http-fixture-')
        self.env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(Path(self.tmp.name)/'test.sqlite')}
        self.php=os.environ.get('ERP_TEST_PHP','php')
        self.fixture=json.loads(subprocess.run([self.php,'tests/http_fixture.php'],cwd=self.root,env=self.env,capture_output=True,text=True,check=True).stdout)
        self.db=sqlite3.connect(self.env['ERP_DB_PATH'])
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        self.base=f'http://127.0.0.1:{port}'
        self.log=open(Path(self.tmp.name)/'server.log','w+',encoding='utf-8')
        server_command=[self.php]+(['-d','session.name='+self.session_name] if self.session_name else [])+['-S',f'127.0.0.1:{port}','router.php']
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
        if not kind:
            issues=[line for line in logs.splitlines() if re.search(r'PHP (Warning|Deprecated|Fatal|Parse)',line)]
            assert not issues,'\n'.join(issues)
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
        fields=self.db.execute(f'PRAGMA table_info({table})').fetchall()
        assert fields,'Unknown table'
        keys=[row[1] for row in sorted(fields,key=lambda row:row[5]) if row[5]]
        order=','.join('"'+key.replace('"','""')+'"' for key in keys) if keys else '1'
        return self.db.execute(f'SELECT * FROM {table} ORDER BY {order}').fetchall()
