// admin/stockIn.js
lucide.createIcons();

// Function to open edit modal with user data
// Function to open edit modal with item data
function editItem(data) {
  document.getElementById("stockInId").value = data.SI_ID;
  document.getElementById("stock_no").value = data.ITEM_ID;
  // document.getElementById("desc").value = data.ITEM_ID;
  document.getElementById("quantity").value = data.SI_QUANTITY;
  document.getElementById("remarks").value = data.SI_REMARKS;

  document.getElementById("modalText").innerText = "Edit Item";
  document.getElementById("modal").classList.remove("hidden");

  document.getElementById("stock_no").focus();
}

// Open modal for adding a new item
document.getElementById("openModal").addEventListener("click", function () {
  document.getElementById("form").reset();
  document.getElementById("stockInId").value = "";
  document.getElementById("modal").classList.remove("hidden");
  document.getElementById("modalText").innerText = "Stock In";
  document.getElementById("stock_no").focus();
});
// Close modal
document.getElementById("closeModal").addEventListener("click", function () {
  document.getElementById("modal").classList.add("hidden");
  document.getElementById("form").reset(); // Clear form
});

// Function to prompt for admin password
async function promptPassword() {
  const { value: password } = await Swal.fire({
    title: "Verification Required",
    input: "password",
    inputLabel: "Enter your password to continue",
    inputPlaceholder: "Password",
    showCancelButton: true,
    reverseButtons: true,
    inputValidator: (value) => {
      if (!value) {
        return "Password is required!";
      }
    },
  });

  return password;
}

// Form submission (Add/Edit)
document
  .getElementById("form")
  .addEventListener("submit", async function (event) {
    event.preventDefault();

    const formData = {
      stockInId: document.getElementById("stockInId").value.trim(),
      item_id: document.getElementById("stock_no").value.trim(),
      quantity: document.getElementById("quantity").value.trim(),
      remarks: document.getElementById("remarks").value.trim(),
      action: document.getElementById("stockInId").value ? "edit" : "add",
    };

    // Validate required fields
    if (!formData.quantity || !formData.remarks || !formData.item_id) {
      Swal.fire({
        icon: "warning",
        title: "Missing Fields",
        text: "Please fill in all required fields before submitting.",
      });
      return;
    }

    let password = null;

    if (formData.action === "edit") {
      const result = await Swal.fire({
        title: "Are you sure?",
        text: "This action will update the stock in record.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        confirmButtonText: "Yes, update it!",
        reverseButtons: true,
      });

      if (!result.isConfirmed) return;

      password = await promptPassword();
      if (!password) return;
    } else {
      password = formData.pass;
    }

    // Show loading state
    Swal.fire({
      title: "Updating...",
      text: "Please wait while we update the item",
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      },
    });

    fetch("stockIn-php.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        ...formData,
        password: password,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.status === "success") {
          Swal.fire({
            icon: "success",
            title: "Success!",
            text: data.message,
          }).then(() => {
            document.getElementById("form").reset();
            document.getElementById("modal").classList.add("hidden");
            setTimeout(() => location.reload(), 100);
          });
        } else {
          Swal.fire({ icon: "error", title: "Error", text: data.message });
        }
      })
      .catch((error) => {
        console.error("Fetch error:", error);
        Swal.fire({
          icon: "error",
          title: "Request Failed",
          text: "An error occurred. Please try again.",
        });
        console.log(formData);
      });
  });

// Item search functionality for modal
const itemSearch = document.getElementById("itemSearch");
const searchResults = document.getElementById("searchResults");
const stockNoSelect = document.getElementById("stock_no");

// Get all options from select
const allOptions = Array.from(stockNoSelect.options).slice(1); // Skip first disabled option

// Create searchable items array
const searchableItems = allOptions.map((opt) => ({
  id: opt.value,
  code: opt.dataset.code,
  desc: opt.dataset.desc,
  text: opt.textContent,
}));

// Search function
itemSearch.addEventListener("input", function () {
  const searchTerm = this.value.toLowerCase().trim();

  if (searchTerm === "") {
    searchResults.classList.add("hidden");
    return;
  }

  // Filter items
  const filtered = searchableItems.filter(
    (item) =>
      item.code.toLowerCase().includes(searchTerm) ||
      item.desc.toLowerCase().includes(searchTerm) ||
      item.text.toLowerCase().includes(searchTerm),
  );

  // Display results
  if (filtered.length > 0) {
    searchResults.innerHTML = filtered
      .map(
        (item) => `
            <div class="p-2 hover:bg-blue-50 cursor-pointer border-b last:border-0" 
                 onclick="selectItem('${item.id}')">
                <div class="text-sm font-medium">${item.code}</div>
                <div class="text-xs text-gray-600">${item.desc}</div>
            </div>
        `,
      )
      .join("");
    searchResults.classList.remove("hidden");
  } else {
    searchResults.innerHTML =
      '<div class="p-2 text-sm text-gray-500">No items found</div>';
    searchResults.classList.remove("hidden");
  }
});

// Select item function
window.selectItem = function (itemId) {
  // Set the select value
  stockNoSelect.value = itemId;

  // Clear search and hide results
  itemSearch.value = "";
  searchResults.classList.add("hidden");

  // Trigger change event for any dependent logic
  stockNoSelect.dispatchEvent(new Event("change"));

  // Optional: Show selected item feedback
  const selectedOption = stockNoSelect.options[stockNoSelect.selectedIndex];
};

// Hide search results when clicking outside
document.addEventListener("click", function (e) {
  if (!itemSearch.contains(e.target) && !searchResults.contains(e.target)) {
    searchResults.classList.add("hidden");
  }
});

// Show results when focusing on search if there's text
itemSearch.addEventListener("focus", function () {
  if (this.value.trim() !== "") {
    this.dispatchEvent(new Event("input"));
  }
});

// Keyboard navigation
itemSearch.addEventListener("keydown", function (e) {
  if (e.key === "Escape") {
    searchResults.classList.add("hidden");
  }
});
