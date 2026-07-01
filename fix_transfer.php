<?php
$file = 'batch/manage_student.php';
$content = file_get_contents($file);

$target_transfer = <<<PHP
                                        // Update students' batch
                                        \$stmt = \$db->prepare("UPDATE students SET batch_name = ?, current_status = 'active' WHERE student_id IN (\$placeholders) AND batch_name = ?");
                                        \$params = array_merge([\$target_batch], \$valid_students);
                                        \$params[] = \$batch_id;
                                        \$stmt->execute(\$params);
PHP;

$replacement_transfer = <<<PHP
                                        // Update students' batch dynamically for whichever column has the old batch_id
                                        \$stmt = \$db->prepare("
                                            UPDATE students 
                                            SET 
                                                batch_name = CASE WHEN batch_name = ? THEN ? ELSE batch_name END,
                                                batch_name_2 = CASE WHEN batch_name_2 = ? THEN ? ELSE batch_name_2 END,
                                                batch_name_3 = CASE WHEN batch_name_3 = ? THEN ? ELSE batch_name_3 END,
                                                batch_name_4 = CASE WHEN batch_name_4 = ? THEN ? ELSE batch_name_4 END,
                                                current_status = 'active'
                                            WHERE student_id IN (\$placeholders) 
                                              AND (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?)
                                        ");
                                        \$params = array_merge(
                                            [\$batch_id, \$target_batch, \$batch_id, \$target_batch, \$batch_id, \$target_batch, \$batch_id, \$target_batch],
                                            \$valid_students,
                                            [\$batch_id, \$batch_id, \$batch_id, \$batch_id]
                                        );
                                        \$stmt->execute(\$params);
PHP;

$content = str_replace($target_transfer, $replacement_transfer, $content);
file_put_contents($file, $content);
echo "Fixed transfer.\n";
?>
