import re

file_path = r'c:\xampp\htdocs\version3\batch\progress_batch.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add course_id retrieval
if ' = ['course_id'] ?? ''' not in content:
    content = content.replace(
        "\ = \['batch_id'] ?? '';",
        "\ = \['batch_id'] ?? '';\n\ = \['course_id'] ?? '';\n\n// Course Condition Helper\n\ = !empty(\) ? ' AND course_id = ?' : '';\n\ = !empty(\) ? ', course_id' : '';\n\ = !empty(\) ? ', ?' : '';"
    )

# 2. Line 38: INSERT INTO main_topics
content = content.replace(
    ''' = ->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name, topic_type) VALUES (?, ?, ?, ?)");''',
    ''' = ->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name, topic_type" .  . ") VALUES (?, ?, ?, ?" .  . ")");'''
)
content = content.replace(
    '''->execute([, [], [], ]);''',
    '''
                 = [, [], [], ];
                if (!empty()) [] = ;
                ->execute();'''
)

# Line 103: UPDATE main_topics
content = content.replace(
    ''' = ->prepare("UPDATE main_topics SET chapter = ?, topic_name = ?, topic_type = ? WHERE id = ? AND batch_name = ?");''',
    ''' = ->prepare("UPDATE main_topics SET chapter = ?, topic_name = ?, topic_type = ? WHERE id = ? AND batch_name = ?" . );'''
)
content = content.replace(
    '''->execute([, , , , ]);''',
    ''' = [, , , , ];
        if (!empty()) [] = ;
        ->execute();'''
)

# Line 117: DELETE main_topics
content = content.replace(
    ''' = ->prepare("DELETE FROM main_topics WHERE id = ? AND batch_name = ?");''',
    ''' = ->prepare("DELETE FROM main_topics WHERE id = ? AND batch_name = ?" . );'''
)
content = content.replace(
    '''->execute([, ]);''',
    ''' = [, ];
        if (!empty()) [] = ;
        ->execute();'''
)

# Line 149: UPDATE is_active
content = content.replace(
    ''' = ->prepare("UPDATE main_topics SET is_active = NOT is_active WHERE id = ? AND batch_name = ?");''',
    ''' = ->prepare("UPDATE main_topics SET is_active = NOT is_active WHERE id = ? AND batch_name = ?" . );'''
)
content = content.replace(
    '''->execute([, ]);''',
    ''' = [, ];
        if (!empty()) [] = ;
        ->execute();'''
)

# Line 192: SELECT
content = content.replace(
    ''' = ->prepare("SELECT id FROM main_topics WHERE batch_name = ? AND chapter = ?");''',
    ''' = ->prepare("SELECT id FROM main_topics WHERE batch_name = ? AND chapter = ?" . );'''
)
content = content.replace(
    '''->execute([, ]);''',
    ''' = [, ];
                                        if (!empty()) [] = ;
                                        ->execute();'''
)

# Line 202: INSERT (CSV)
content = content.replace(
    ''' = ->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name, topic_type) VALUES (?, ?, ?, ?)");''',
    ''' = ->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name, topic_type" .  . ") VALUES (?, ?, ?, ?" .  . ")");'''
)
content = content.replace(
    '''->execute([, , , ]);''',
    ''' = [, , , ];
                                            if (!empty()) [] = ;
                                            ->execute();'''
)

# Line 226: SELECT (CSV Sub topics)
content = content.replace(
    ''' = ->prepare("SELECT id, topic_type FROM main_topics WHERE batch_name = ? AND chapter = ?");''',
    ''' = ->prepare("SELECT id, topic_type FROM main_topics WHERE batch_name = ? AND chapter = ?" . );'''
)
content = content.replace(
    '''->execute([, ]);''',
    ''' = [, ];
                                        if (!empty()) [] = ;
                                        ->execute();'''
)

# Line 293: SELECT main topics
content = content.replace(
    ''' = ->prepare("SELECT * FROM main_topics WHERE batch_name = ? AND is_active = 1 ORDER BY chapter");''',
    ''' = ->prepare("SELECT * FROM main_topics WHERE batch_name = ?" .  . " AND is_active = 1 ORDER BY chapter");'''
)
content = content.replace(
    '''->execute([]);''',
    ''' = [];
if (!empty()) [] = ;
->execute();'''
)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Update completed.")
