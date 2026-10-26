"""Structured bilingual authoring and paper output on disposable SQLite or native MySQL."""
import json
from http_support import ERPHttpFixture, visible_exam_text
from learning_sharing_support import extend_learning


def payload_parts(parts):
    values={}
    for i,part in enumerate(parts):
        for key,value in part.items():values[f'subquestions[{i}][{key}]']=value
    return values


def run():
    with ERPHttpFixture() as app:
        f=extend_learning(app);cls=f['class']
        subject=app.db.execute("SELECT s.id FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=s.school_id WHERE cs.class_id=? AND s.name_en='Mathematics' AND cs.is_active=1",(cls,)).fetchone()[0]
        app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE school_id=1 AND class_id=? AND subject_id=?',(str(f['ids']['teacher']),cls,subject))
        ch=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Structured compound interest')",(cls,subject)).lastrowid
        topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Compound interest')",(cls,subject,ch)).lastrowid;app.db.commit()
        protected={name:app.snapshot(name) for name in ('students','marks','exams','ac_payments','chapters','topics')}
        app.login('teacher')
        parts=[dict(label='a',en='<p>State the interest formula.</p>',np='<p>ब्याजको सूत्र लेख्नुहोस्।</p>',marks='1',level='K'),dict(label='b',en=r'<p>Find the first year interest \(I=A-P\).</p>',np='<p>पहिलो वर्षको ब्याज पत्ता लगाउनुहोस्।</p>',marks='2',level='KU'),dict(label='c',en='<p>Compare the second year.</p>',np='<p>दोस्रो वर्षको तुलना गर्नुहोस्।</p>',marks='1',level='HA')]
        base=dict(action='add',bank_ui='1',class_id=cls,subject_id=subject,chapter='Structured compound interest',topic_id=topic,type='Long',question_layout='structured',marks='999',required_minutes='8',difficulty='Medium',cognitive_domain='Application',visibility='private',statement_en='<p>A depositor invests Rs. 2,00,000.</p>',statement_np='<p>एक व्यक्तिले रु. 2,00,000 जम्मा गरे।</p>')
        for route,bank,generator in [('/learning/question-bank','learning','/learning/paper-generator'),('/exam-questions','exam','/exam-paper-generator')]:
            add=app.request(route+f'?view=add&class_id={cls}&subject_id={subject}')
            assert 'id="questionPartsPanel"' in add and 'Marks + cognitive category' in add
            for index,(display,suffix) in enumerate((('both','(2KU)'),('marks','(3)'),('cognitive','(KU)'))):
                selected=[dict(p) for p in parts];selected[1]['marks']=str(2+index)
                data={**base,'part_display':display,**payload_parts(selected)}
                app.request(route+'?view=add',data)
                row=app.db.execute('SELECT id,marks,question_text,question_text_np FROM questions WHERE bank_kind=? ORDER BY id DESC LIMIT 1',(bank,)).fetchone();qid=row[0]
                assert float(row[1])==4+index and 'data-part-levels="KU"' in row[2] and 'data-part-display="'+display+'"' in row[3]
                body=app.request(route+f'?record_id={qid}')
                assert suffix in body and body.index('एक व्यक्ति')<body.index('A depositor')<body.index('ब्याजको सूत्र')<body.index('State the interest formula.')
                assert body.count('class="exam-part-score"')==3
                english=app.request(route+f'?record_id={qid}&language=en');nepali=app.request(route+f'?record_id={qid}&language=ne')
                english_first=app.request(route+f'?record_id={qid}&language=both_en')
                assert english_first.index('A depositor')<english_first.index('एक व्यक्ति') and english_first.index('State the interest formula.')<english_first.index('ब्याजको सूत्र')
                assert english_first.count('exam-subquestion-pair')==3
                assert 'A depositor' in english and 'एक व्यक्ति' not in english
                assert 'एक व्यक्ति' in nepali and 'A depositor' not in nepali and suffix in nepali
                edit=app.request(route+f'?view=edit&question_id={qid}')
                assert 'question-parts-config' in edit and '&quot;KU&quot;' in edit
                if bank=='exam':
                    grid={'full_marks':str(4+index),'duration_minutes':'20','rows':[{'name':'Interest','type':'Long','chapter_ids':[ch],'count':1,'total_marks':str(4+index),'marks_each':str(4+index)}],'cognitive_marks':{'K':'1','KU':str(2+index),'HA':'1'}}
                    sheet=app.request(generator,dict(action='generate',class_id=cls,subject_id=subject,exam_title='Structured QA',config_json=json.dumps(grid),language='both'))
                else:
                    allocation=[{'chapter_id':ch,'topic_id':topic,'type':'Long','count':1,'total_marks':str(4+index)}]
                    sheet=app.request(generator,dict(action='generate_test',class_id=cls,subject_id=subject,paper_title='Structured QA',allocations_json=json.dumps({'rows':allocation,'duration_minutes':20}),language='both'))
                visible=visible_exam_text(sheet)
                assert suffix in visible and 'A depositor' in visible and 'एक व्यक्ति' in visible
                question_sheet=sheet.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0]
                assert '</div><span>['+str(4+index)+'.00]</span></div>' not in question_sheet
                answer_sheet=sheet.split('id="examAnswersSheet"')[1]
                assert 'Subquestion marking guide' in answer_sheet and f'(b) ({2+index}'+(')' if display=='marks' else 'KU)') in answer_sheet
            current=app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]
            invalid=[dict(p) for p in parts];invalid[0]['level']='KU'
            result=app.request(route+'?view=add',{**base,**payload_parts(invalid)},status=422);assert 'first must include K' in result
            invalid=[dict(p) for p in parts];invalid[2]['level']='U'
            app.request(route+'?view=add',{**base,**payload_parts(invalid)},status=422)
            invalid=[dict(p) for p in parts];invalid[1]['marks']='0'
            app.request(route+'?view=add',{**base,**payload_parts(invalid)},status=422)
            app.request(route+'?view=add',{**base,'part_display':'<script>',**payload_parts(parts)},status=422)
            app.request(route+'?view=add',{**base,'school_id':f['otherSchool'],**payload_parts(parts)},status=403)
            assert app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]==current
            # An edit changes only the chosen record and recomputes its total.
            changed=[dict(p) for p in parts];changed[1]['marks']='2.5'
            app.request(route+f'?view=edit&question_id={qid}',{**base,'action':'edit','question_id':qid,'part_display':'both',**payload_parts(changed)})
            saved=app.db.execute('SELECT marks FROM questions WHERE id=?',(qid,)).fetchone()[0];assert float(saved)==4.5
        app.login('other_admin');app.request('/exam-questions?view=edit&question_id='+str(qid),status=404)
        for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed'
        print(f'Structured question HTTP passed: {app.count} requests; both banks, all suffix styles, bilingual print, independent KU, forged totals, math rules, editing and tenant isolation.')


if __name__=='__main__':run()
