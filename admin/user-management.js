// admin/user-management.js
lucide.createIcons();

// Function to open edit modal with user data
function editUser(user) {
  document.getElementById("userId").value = user.USER_ID;
  document.getElementById("fname").value = user.USER_FNAME;
  document.getElementById("lname").value = user.USER_LNAME;
  document.getElementById("email").value = user.USER_EMAIL;
  document.getElementById("role").value = user.UR_ROLE;

  document.getElementById("modal").classList.remove("hidden");

  document.getElementById("modalText").innerText = "Edit User";
  document.getElementById("fname").focus();
}

// Open modal for adding a user
document.getElementById("openModal").addEventListener("click", function () {
  document.getElementById("form").reset();
  document.getElementById("userId").value = ""; // Ensure userId is empty fors new user
  document.getElementById("modal").classList.remove("hidden");
  document.getElementById("fname").focus();

  document.getElementById("modalText").innerText = "Add New User";
});

// Close modal
document.getElementById("closeModal").addEventListener("click", function () {
  document.getElementById("modal").classList.add("hidden");
  document.getElementById("form").reset(); // Clear form
});

// Function to prompt for SA email and password
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
      user_id: document.getElementById("userId").value.trim(), // Use correct key `user_id`
      fname: document.getElementById("fname").value.trim(),
      lname: document.getElementById("lname").value.trim(),
      email: document.getElementById("email").value.trim(),
      pass: document.getElementById("pass").value.trim(),
      role: document.getElementById("role").value.trim(),
      action: document.getElementById("userId").value ? "edit" : "add",
    };

    // Validate required fields
    if (
      !formData.fname ||
      !formData.lname ||
      !formData.email ||
      !formData.role
    ) {
      Swal.fire({
        icon: "warning",
        title: "Missing Fields",
        text: "Please fill in all fields before submitting.",
      });
      return;
    }

    if (formData.action === "add" && !formData.pass) {
      Swal.fire({
        icon: "warning",
        title: "Missing Password",
        text: "Password is required when adding a new user.",
      });
      return;
    }

    // Validate password length when adding a user
    if (formData.action === "add" && formData.pass.length < 8) {
      Swal.fire({
        icon: "warning",
        title: "Weak Password",
        text: "Password must be at least 8 characters long.",
      });
      return;
    }

    let password = null;
    if (formData.action === "edit") {
      password = await promptPassword();
      if (!password) return;
    } else {
      password = formData.pass; // Use the entered password for new user
    }

    // Show loading state
    Swal.fire({
      title: "Updating...",
      text: "Please wait while we update the profile",
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      },
    });

    fetch("user-management-php.php", {
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
        console.error(formData);
      });
  });

// Delete user function
async function deleteUser(userId) {
  if (!userId) {
    Swal.fire({
      icon: "warning",
      title: "Invalid User",
      text: "User ID is required to archive a user.",
    });
    return;
  }

  Swal.fire({
    title: "Are you sure?",
    text: "This action cannot be undone!",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, archive it!",
    reverseButtons: true,
  }).then(async (result) => {
    if (result.isConfirmed) {
      const password = await promptPassword();
      if (!password) return;
      // Show loading state
      Swal.fire({
        title: "Updating...",
        text: "Please wait while we update the item",
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        },
      });
      fetch("user-management-php.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          user_id: userId,
          action: "archive",
          password: password,
        }), // Ensure `user_id` is sent
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.status === "success") {
            Swal.fire({
              icon: "success",
              title: "Archived!",
              text: data.message,
            }).then(() => location.reload());
          } else {
            Swal.fire({ icon: "error", title: "Error", text: data.message });
          }
        })
        .catch((error) => {
          console.error("Error:", error);
          Swal.fire({
            icon: "error",
            title: "Request Failed",
            text: "An error occurred. Please try again.",
          });
        });
    }
  });
}
