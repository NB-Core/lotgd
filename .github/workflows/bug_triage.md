---
on:
  issues:
    types: [opened]
permissions:
  contents: read
  actions: read
safe-outputs:
  add-labels:
    allowed: [bug, needs-info, enhancement, question, documentation]  # Restrict to specific labels
    max: 2                                                            # Maximum 2 labels per issue
---

# Bug Report Triage

Analyze new issues and add appropriate labels: "bug" (with repro steps), "needs-info" (missing details), "enhancement" (features), "question" or "documentation" (help/docs). Maximum 2 labels from the allowed list.
