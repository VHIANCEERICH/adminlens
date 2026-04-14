import json
import os
import sys

import matplotlib

matplotlib.use("Agg")

import matplotlib.pyplot as plt


def main() -> int:
    if len(sys.argv) < 2:
        print("Missing JSON payload.", file=sys.stderr)
        return 1

    try:
        product = json.loads(sys.argv[1])

        sku = str(product["sku"])
        product_name = str(product["product_name"])
        stock_on_hand = int(product["stock_on_hand"])
        reorder_point = int(product["reorder_point"])
        units_sold = int(product["units_sold"])
        rank = str(product.get("rank", "normal"))
        output_dir = str(product.get("output_dir", os.path.join("assets", "charts")))

        sold_color = "steelblue"
        if rank == "best":
            sold_color = "#16a34a"
        elif rank == "least":
            sold_color = "#dc2626"

        labels = ["Units Sold", "Stock on Hand"]
        values = [units_sold, stock_on_hand]
        colors = [sold_color, "steelblue"]

        fig, ax = plt.subplots(figsize=(8, 3), dpi=120)
        ax.barh(labels, values, color=colors)
        ax.axvline(reorder_point, color="#dc2626", linestyle="--", linewidth=2)
        ax.text(
            reorder_point,
            1.25,
            f"Reorder: {reorder_point}",
            color="#dc2626",
            ha="center",
            va="bottom",
            fontsize=9,
        )
        ax.set_title(product_name)
        ax.set_xlabel("Quantity")
        ax.invert_yaxis()
        plt.tight_layout()

        os.makedirs(output_dir, exist_ok=True)
        output_path = os.path.join(output_dir, f"{sku}_chart.png")
        plt.savefig(output_path)
        plt.close(fig)
        return 0
    except Exception as exc:
        print(f"Chart generation failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
