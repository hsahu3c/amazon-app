# Description optimization (optional)

Prepared queries: `trigger-queries-description-optimization.json`.

When ready, from `.cursor/skills/skill-creator` run (requires `claude` CLI and model id used in your environment):

```bash
python3 -m scripts.run_loop \
  --eval-set ../shopify-amazon-shipment-sync/evals/trigger-queries-description-optimization.json \
  --skill-path ../shopify-amazon-shipment-sync \
  --model <model-id> \
  --max-iterations 5 \
  --verbose
```

Apply `best_description` from the output into `shopify-amazon-shipment-sync/SKILL.md` frontmatter.
