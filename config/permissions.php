<?php

// Author: ramanpal singh | URL: https://kwebby.com
return ['roles' => [
    'owner' => ['*' => 'firm'], 'admin' => ['*' => 'firm'],
    'partner' => ['time_entries.*' => 'firm', 'expenses.*' => 'firm', 'leads.*' => 'firm', 'contacts.*' => 'firm', 'matters.*' => 'firm', 'proceedings.*' => 'firm', 'tasks.*' => 'firm', 'documents.*' => 'firm', 'conflicts.*' => 'firm', 'engagements.*' => 'firm', 'conversations.*' => 'firm', 'messages.*' => 'firm', 'invoices.read' => 'firm', 'ai_runs.*' => 'firm', 'ai.*' => 'firm', 'notifications.*' => 'assigned', 'team.read' => 'firm'],
    'lawyer' => ['time_entries.read' => 'assigned', 'time_entries.write' => 'assigned', 'expenses.read' => 'assigned', 'expenses.write' => 'assigned', 'leads.*' => 'assigned', 'contacts.*' => 'assigned', 'matters.*' => 'assigned', 'proceedings.*' => 'assigned', 'tasks.*' => 'assigned', 'documents.*' => 'assigned', 'conversations.*' => 'assigned', 'messages.*' => 'assigned', 'ai_runs.*' => 'assigned', 'ai.*' => 'assigned', 'notifications.*' => 'assigned', 'invoices.read' => 'assigned'],
    'paralegal' => ['time_entries.read' => 'assigned', 'time_entries.write' => 'assigned', 'expenses.read' => 'assigned', 'expenses.write' => 'assigned', 'matters.read' => 'assigned', 'contacts.read' => 'assigned', 'proceedings.*' => 'assigned', 'tasks.*' => 'assigned', 'documents.*' => 'assigned', 'conversations.*' => 'assigned', 'messages.*' => 'assigned', 'notifications.*' => 'assigned'],
    'intake' => ['leads.*' => 'firm', 'contacts.*' => 'firm', 'tasks.*' => 'assigned', 'conversations.*' => 'assigned', 'messages.*' => 'assigned', 'notifications.*' => 'assigned'],
    'accounts' => ['time_entries.*' => 'firm', 'expenses.*' => 'firm', 'recurring_invoices.*' => 'firm', 'matters.read' => 'assigned', 'invoices.*' => 'firm', 'payments.*' => 'firm', 'receipts.*' => 'firm', 'contacts.read' => 'firm', 'notifications.*' => 'assigned'],
    'hr' => ['payroll.*' => 'firm', 'payroll_runs.*' => 'firm', 'payslips.*' => 'firm', 'employees.*' => 'firm', 'notifications.*' => 'assigned'],
    'content' => ['pages.*' => 'firm', 'themes.read' => 'firm', 'seo.*' => 'firm', 'notifications.*' => 'assigned'],
    'collaborator' => ['matters.read' => 'assigned', 'tasks.*' => 'assigned', 'documents.read' => 'assigned', 'conversations.*' => 'assigned', 'messages.*' => 'assigned', 'notifications.*' => 'assigned'],
]];
