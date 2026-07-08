Project Specification: Freelancer Job Platform
1. Project Overview
A role-based web application allowing Companies to post jobs, Freelancers to apply and work on them, and Admins to moderate content.

2. Tech Stack
Backend: PHP 8.x (Native/Procedural)

Database: MySQL (using mysqli with Prepared Statements)

Frontend: HTML5, Tailwind CSS (via CDN for simplicity)

Security: password_hash() for encryption, Session-based authentication

3. Directory Structure

/freelance-platform
├── /assets/css         # Tailwind CDN / Custom Styles
├── /config             # db.php (Database connection)
├── /includes           # header.php, navbar.php, footer.php
├── /uploads            # User-submitted files
├── /admin              # admin_dashboard.php, approve_jobs.php
├── /company            # post_job.php, view_applications.php
├── /freelancer         # browse_jobs.php, my_tasks.php
├── index.php           # Landing page
├── login.php
├── register.php
└── logout.php

3. Core Features
A. Authentication

Register: Allow users to sign up by selecting their role (Company or Freelancer).

Login: Use PHP sessions to manage user login and redirect them to their respective dashboards.

B. Company Features

Job Posting: Submit a job posting form and save it to the database with a status = 'pending' value.

Manage Jobs: View and edit the jobs posted by the company.

Hire: Review freelancer applications to accept or reject them, and assign the job to a selected freelancer.

Payment: Confirm the completion of the job and process the payment to the freelancer.

C. Freelancer Features

Job Search: Browse and search for available jobs.

Apply: Apply for jobs that match the freelancer's profile and skills.

Work/Task: View assigned tasks and submit a file link upon completion.

D. Admin Features

Job Approval: Review jobs posted by companies and update the status to 'approved' to make them visible to freelancers.