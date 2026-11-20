# Policies and decisions

A reflex gets the tools of its MCP connections, each one prefixed with the connection's name, like `shop_issue_coupon`. Its policy decides, in code, what the agent may do with each of them.

## Tool policies

| Policy | What the agent gets | What happens |
| --- | --- | --- |
| **Automatic** | The tool | It runs, and the result goes back to the agent |
| **Needs approval** | A tool with the same name and arguments that only records a proposal | A decision waits for a person |
| **Forbidden** | Nothing | The tool doesn't exist for the agent, so no prompt can make it call it |

A tool without a policy of its own is automatic when its server marks it read-only, and needs approval otherwise. Policies are edited on the reflex page, next to each tool and its annotations (read-only, idempotent, destructive).

A tool that needs approval can also need more than one approval, from different people: for example, a refund approved by two owners.

## Decisions

When the agent calls a tool that needs approval, the call is recorded, not run, and the agent is told so. The run carries on with what it can do on its own, and then waits.

The decision shows the reason the agent gave, and the exact tool and arguments that run if approved. Then:

- **Approve** runs exactly that call, without asking the model again.
- **Reject**, with an optional note, runs nothing.

Once every proposal of the run is decided, the run resumes: the agent is told each outcome, with the notes, and finishes its work or proposes something else.

People with the admin role decide, from the **Decisions** page, from the console (`bin/console reflex:decide`), or from an MCP client connected to Reflex. Viewers see decisions but can't decide them.

## Server questions

An MCP server can stop in the middle of a call to ask something (elicitation). In the demo, refunding an order makes the shop ask whether to refund the card or give store credit.

The question becomes a decision too, with a form built from the schema the server sent. **Answer** repeats the call with the answer; **Decline** doesn't repeat it, and the agent is told.

## Memory

Every decision, approved, rejected, answered or declined, is remembered as a precedent: the call, the agent's reason, the outcome and the note. Its text is embedded by a local Text Embeddings Inference server (`intfloat/multilingual-e5-small`) and stored in PostgreSQL with pgvector, through Symfony AI's Store.

When a reflex runs, it recalls up to 3 precedents of the same reflex close enough to the new event. The agent is told to follow them, not to propose again what people rejected for the same reason, and to say which precedent it followed. The reflex page lists its memory, and each run shows the precedents it recalled.

## What the model reads as data

The event, the outcomes of decisions and the precedents reach the model inside their own tags (`<event>`, `<decision>`, `<memory>`), and its instructions say that what is inside them is data to analyse, never instructions. If an event asks it to ignore its instructions, it reports that instead of doing it. The run page marks the event as **untrusted data**.

## Limits

Each reflex has limits of its own, set in its form:

| Limit | When it's reached |
| --- | --- |
| Daily token budget | New runs are skipped until midnight UTC |
| Tool calls per run | The run stops |
| Time per run | The run stops |
| 3 failed runs in a row | The reflex turns itself off, and says why on its page |

## Runs that survive a crash

Runs are queued with Messenger. Every tool call is stored as a step with an idempotency key, made of the run, the position of the call, the tool and its arguments.

If the worker dies mid-run, the run starts again and the agent's calls are answered from the stored steps instead of running again. A call that was interrupted halfway runs again only if its server marks it read-only or idempotent; otherwise it is marked failed, and the agent is told to check the current state before acting again.

Finished runs older than `REFLEX_RETENTION_DAYS` are deleted every night.
