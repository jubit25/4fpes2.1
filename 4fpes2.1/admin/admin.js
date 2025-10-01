// User search functionality
function filterUsersTable() {
    const searchInput = document.getElementById('user_search');
    const table = document.getElementById('users_table');
    const searchTerm = searchInput.value.toLowerCase();
    
    if (!table) return;
    
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        let shouldShow = false;
        
        // Search in name, username, role, and department columns (0, 1, 2, 3)
        for (let j = 0; j < 4; j++) {
            if (cells[j] && cells[j].textContent.toLowerCase().includes(searchTerm)) {
                shouldShow = true;
                break;
            }
        }
        
        rows[i].style.display = shouldShow ? '' : 'none';
    }
}

function logout() {
    fetch('../auth.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=logout'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        window.location.href = '../index.php';
    });
}

function showSection(section) {
  document.getElementById("home-section").style.display = section === 'home' ? 'block' : 'none';
  document.getElementById("manage-section").style.display = section === 'manage' ? 'block' : 'none';
  if (section === 'manage') renderUsers();
}

async function renderUsers() {
  const res = await fetch("http://localhost:3000/users");
  const users = await res.json();

  const tbody = document.querySelector("#userTable tbody");
  tbody.innerHTML = "";
  users.forEach(u => {
    tbody.innerHTML += `
      <tr>
        <td>${u.username}</td>
        <td>${u.role}</td>
        <td><button onclick="deleteUser('${u.username}')">Delete</button></td>
      </tr>`;
  });
}

document.getElementById("userForm").addEventListener("submit", async e => {
  e.preventDefault();

  const newUser = {
    username: document.getElementById("username").value,
    password: document.getElementById("password").value,
    role: document.getElementById("role").value
  };

  await fetch("http://localhost:3000/users", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(newUser)
  });

  e.target.reset();
  renderUsers();
});

async function deleteUser(username) {
  await fetch(`http://localhost:3000/users/${username}`, { method: "DELETE" });
  renderUsers();
}
