<?php
function check_and_update_expired_jobs($conn) {
    // Only target jobs that are open (Active), deadline has passed, and NO freelancer has been hired
    $sql = "UPDATE jobs j 
            SET j.status = 'expired' 
            WHERE j.status = 'open' 
            AND j.deadline IS NOT NULL 
            AND j.deadline < NOW()
            AND NOT EXISTS (SELECT 1 FROM assignments a WHERE a.job_id = j.id)";
    $conn->query($sql);
}
